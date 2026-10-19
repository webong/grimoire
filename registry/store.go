package registry

import (
	"context"
	"errors"
)

var (
	ErrNotFound = errors.New("grimoire object not found")
	ErrConflict = errors.New("grimoire revision conflict")
)

type PublicationStatus string

const (
	PublicationDraft     PublicationStatus = "draft"
	PublicationPublished PublicationStatus = "published"
	PublicationRetired   PublicationStatus = "retired"
)

type Reference struct {
	Ref      Ref               `json:"ref"`
	Digest   Digest            `json:"digest"`
	Status   PublicationStatus `json:"status"`
	Revision string            `json:"revision"`
}

type ReferenceQuery struct {
	Kind   ResourceKind
	Status PublicationStatus
}

// Store is the persistence boundary for embedded and remote Grimoire stores.
// Objects are immutable. References are updated with compare-and-swap revisions.
type Store interface {
	PutObject(ctx context.Context, raw []byte) (Digest, error)
	GetObject(ctx context.Context, digest Digest) ([]byte, error)
	GetReference(ctx context.Context, ref Ref) (Reference, error)
	PutReference(ctx context.Context, reference Reference, expectedRevision *string) (Reference, error)
	ListReferences(ctx context.Context, query ReferenceQuery) ([]Reference, error)
}
