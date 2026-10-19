// Package packstore stores an entire Grimoire registry in one append-only file.
package packstore

import (
	"context"
	"crypto/sha256"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"sort"
	"sync"

	"github.com/webong/grimoire/internal/registry"
	"golang.org/x/sys/unix"
)

const (
	fileHeaderSize   = 16
	frameHeaderSize  = 41
	formatVersion    = uint32(1)
	frameObject      = byte(1)
	frameReference   = byte(2)
	maxObjectSize    = uint64(512 << 20)
	maxReferenceSize = uint64(1 << 20)
)

var fileMagic = [8]byte{'G', 'R', 'I', 'M', 'D', 'B', '0', '1'}

type objectLocation struct {
	offset int64
	length uint64
}

type Store struct {
	mu      sync.Mutex
	file    *os.File
	objects map[registry.Digest]objectLocation
	refs    map[string]registry.Reference
}

// Open opens or creates a single-file registry and takes an exclusive
// non-blocking lock for the lifetime of the Store.
func Open(path string) (*Store, error) {
	if path == "" {
		return nil, errors.New("registry file path is required")
	}
	file, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, err
	}
	if err := unix.Flock(int(file.Fd()), unix.LOCK_EX|unix.LOCK_NB); err != nil {
		file.Close()
		return nil, fmt.Errorf("lock registry file: %w", err)
	}
	store := &Store{file: file, objects: map[registry.Digest]objectLocation{}, refs: map[string]registry.Reference{}}
	if err := store.load(); err != nil {
		store.Close()
		return nil, err
	}
	return store, nil
}

func (s *Store) Close() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.file == nil {
		return nil
	}
	file := s.file
	s.file = nil
	unlockErr := unix.Flock(int(file.Fd()), unix.LOCK_UN)
	closeErr := file.Close()
	return errors.Join(unlockErr, closeErr)
}

func (s *Store) PutObject(ctx context.Context, raw []byte) (registry.Digest, error) {
	if err := ctx.Err(); err != nil {
		return "", err
	}
	if uint64(len(raw)) > maxObjectSize {
		return "", errors.New("object exceeds binary store limit")
	}
	digest := registry.DigestBytes(raw)
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, exists := s.objects[digest]; exists {
		return digest, nil
	}
	offset, err := s.appendFrame(frameObject, raw)
	if err != nil {
		return "", err
	}
	s.objects[digest] = objectLocation{offset: offset + frameHeaderSize, length: uint64(len(raw))}
	return digest, nil
}

func (s *Store) GetObject(ctx context.Context, digest registry.Digest) ([]byte, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	if _, err := registry.ParseDigest(string(digest)); err != nil {
		return nil, err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	location, exists := s.objects[digest]
	if !exists {
		return nil, registry.ErrNotFound
	}
	raw := make([]byte, location.length)
	if _, err := s.file.ReadAt(raw, location.offset); err != nil {
		return nil, err
	}
	if registry.DigestBytes(raw) != digest {
		return nil, errors.New("stored object does not match its digest")
	}
	return raw, nil
}

func (s *Store) GetReference(ctx context.Context, ref registry.Ref) (registry.Reference, error) {
	if err := ctx.Err(); err != nil {
		return registry.Reference{}, err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	value, exists := s.refs[ref.Key()]
	if !exists {
		return registry.Reference{}, registry.ErrNotFound
	}
	return value, nil
}

func (s *Store) PutReference(ctx context.Context, value registry.Reference, expectedRevision *string) (registry.Reference, error) {
	if err := ctx.Err(); err != nil {
		return registry.Reference{}, err
	}
	if err := validateReference(value); err != nil {
		return registry.Reference{}, err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	current, exists := s.refs[value.Ref.Key()]
	if expectedRevision == nil {
		if exists {
			return registry.Reference{}, registry.ErrConflict
		}
	} else if !exists || current.Revision != *expectedRevision {
		return registry.Reference{}, registry.ErrConflict
	}
	revision, err := registry.ReferenceRevision(value)
	if err != nil {
		return registry.Reference{}, err
	}
	value.Revision = revision
	payload, err := json.Marshal(value)
	if err != nil {
		return registry.Reference{}, err
	}
	if uint64(len(payload)) > maxReferenceSize {
		return registry.Reference{}, errors.New("reference exceeds binary store limit")
	}
	if _, err := s.appendFrame(frameReference, payload); err != nil {
		return registry.Reference{}, err
	}
	s.refs[value.Ref.Key()] = value
	return value, nil
}

func (s *Store) ListReferences(ctx context.Context, query registry.ReferenceQuery) ([]registry.Reference, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	result := []registry.Reference{}
	for _, value := range s.refs {
		if query.Kind != "" && value.Ref.Kind != query.Kind {
			continue
		}
		if query.Status != "" && value.Status != query.Status {
			continue
		}
		result = append(result, value)
	}
	sort.Slice(result, func(i, j int) bool { return result[i].Ref.Key() < result[j].Ref.Key() })
	return result, nil
}

func (s *Store) load() error {
	info, err := s.file.Stat()
	if err != nil {
		return err
	}
	if info.Size() == 0 {
		header := make([]byte, fileHeaderSize)
		copy(header[:8], fileMagic[:])
		binary.BigEndian.PutUint32(header[8:12], formatVersion)
		if _, err := s.file.WriteAt(header, 0); err != nil {
			return err
		}
		return s.file.Sync()
	}
	if info.Size() < fileHeaderSize {
		return errors.New("registry file header is truncated")
	}
	header := make([]byte, fileHeaderSize)
	if _, err := s.file.ReadAt(header, 0); err != nil {
		return err
	}
	if string(header[:8]) != string(fileMagic[:]) {
		return errors.New("not a Grimoire registry file")
	}
	if binary.BigEndian.Uint32(header[8:12]) != formatVersion {
		return errors.New("unsupported Grimoire registry format version")
	}
	offset := int64(fileHeaderSize)
	for offset < info.Size() {
		next, err := s.readFrame(offset, info.Size())
		if errors.Is(err, io.ErrUnexpectedEOF) {
			if err := s.file.Truncate(offset); err != nil {
				return err
			}
			return s.file.Sync()
		}
		if err != nil {
			return err
		}
		offset = next
	}
	return nil
}

func (s *Store) readFrame(offset, fileSize int64) (int64, error) {
	if fileSize-offset < frameHeaderSize {
		return offset, io.ErrUnexpectedEOF
	}
	header := make([]byte, frameHeaderSize)
	if _, err := s.file.ReadAt(header, offset); err != nil {
		return offset, err
	}
	kind := header[0]
	length := binary.BigEndian.Uint64(header[1:9])
	limit := maxObjectSize
	if kind == frameReference {
		limit = maxReferenceSize
	} else if kind != frameObject {
		return offset, fmt.Errorf("unknown frame kind %d at offset %d", kind, offset)
	}
	if length > limit {
		return offset, fmt.Errorf("frame at offset %d exceeds size limit", offset)
	}
	payloadOffset := offset + frameHeaderSize
	next := payloadOffset + int64(length)
	if next > fileSize {
		return offset, io.ErrUnexpectedEOF
	}
	payload := make([]byte, length)
	if _, err := s.file.ReadAt(payload, payloadOffset); err != nil {
		return offset, err
	}
	checksum := sha256.New()
	checksum.Write([]byte{kind})
	checksum.Write(payload)
	if !equalBytes(checksum.Sum(nil), header[9:41]) {
		return offset, fmt.Errorf("frame checksum mismatch at offset %d", offset)
	}
	if kind == frameObject {
		digest := registry.DigestBytes(payload)
		s.objects[digest] = objectLocation{offset: payloadOffset, length: length}
	} else {
		var value registry.Reference
		if err := json.Unmarshal(payload, &value); err != nil {
			return offset, fmt.Errorf("decode reference at offset %d: %w", offset, err)
		}
		if err := validateReference(value); err != nil {
			return offset, fmt.Errorf("invalid reference at offset %d: %w", offset, err)
		}
		expectedRevision, err := registry.ReferenceRevision(value)
		if err != nil || expectedRevision != value.Revision {
			return offset, fmt.Errorf("invalid reference revision at offset %d", offset)
		}
		s.refs[value.Ref.Key()] = value
	}
	return next, nil
}

func validateReference(value registry.Reference) error {
	switch value.Ref.Kind {
	case registry.KindRuntime, registry.KindSkill, registry.KindTool, registry.KindPolicy:
	default:
		return fmt.Errorf("unsupported resource kind %q", value.Ref.Kind)
	}
	if err := value.Ref.Validate(value.Ref.Kind); err != nil {
		return err
	}
	if _, err := registry.ParseDigest(string(value.Digest)); err != nil {
		return fmt.Errorf("object digest: %w", err)
	}
	switch value.Status {
	case registry.PublicationDraft, registry.PublicationPublished, registry.PublicationRetired:
		return nil
	default:
		return fmt.Errorf("unsupported publication status %q", value.Status)
	}
}

func (s *Store) appendFrame(kind byte, payload []byte) (int64, error) {
	if s.file == nil {
		return 0, errors.New("registry file is closed")
	}
	offset, err := s.file.Seek(0, io.SeekEnd)
	if err != nil {
		return 0, err
	}
	header := make([]byte, frameHeaderSize)
	header[0] = kind
	binary.BigEndian.PutUint64(header[1:9], uint64(len(payload)))
	checksum := sha256.New()
	checksum.Write([]byte{kind})
	checksum.Write(payload)
	copy(header[9:41], checksum.Sum(nil))
	if _, err := s.file.Write(header); err != nil {
		s.file.Truncate(offset)
		return 0, err
	}
	if _, err := s.file.Write(payload); err != nil {
		s.file.Truncate(offset)
		return 0, err
	}
	if err := s.file.Sync(); err != nil {
		return 0, err
	}
	return offset, nil
}

func equalBytes(left, right []byte) bool {
	if len(left) != len(right) {
		return false
	}
	var difference byte
	for index := range left {
		difference |= left[index] ^ right[index]
	}
	return difference == 0
}
