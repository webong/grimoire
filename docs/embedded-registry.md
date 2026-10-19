# Embedded registry storage semantics

Grimoire uses a DBMS-like model without requiring a database server.

| DBMS concept | Grimoire representation |
| --- | --- |
| Record | Immutable object |
| Primary key | SHA-256 digest |
| Table | Resource kind |
| Secondary index | Rebuildable catalog index |
| Schema | Versioned manifest validator |
| Transaction | Atomic compare-and-swap reference update |
| Snapshot | Resolution lockfile |
| Write-ahead history | Append-only reference journal (planned) |
| Garbage collection | Unreachable object collection (planned) |

## Store contract

The `registry.Store` interface separates the engine from persistence. Objects
support idempotent put/get by digest. References support get, filtered listing,
and compare-and-swap updates using a revision token.

The filesystem backend writes a temporary file, flushes it, applies final
permissions, and atomically renames it into place. Its process mutex makes
concurrent embedded callers safe. A future multi-process filesystem mode needs
an advisory lock or single-writer daemon.

An object-store backend should map immutable objects directly to digest keys
and implement reference compare-and-swap with provider ETags or generation
preconditions. It must never emulate conditional writes with an unchecked
read-then-write sequence.

## Canonical content

Manifest JSON is decoded with numbers preserved and re-encoded with Go's
deterministic JSON encoder before hashing. This is the current v1alpha1 format,
not yet a cross-language canonical JSON standard. Before independent writers
are supported, the format needs published vectors covering numbers, Unicode,
escaping, omitted fields, and nested schemas.

Opaque artifacts are hashed byte-for-byte. Formatting, compression, or archive
changes therefore produce a new artifact digest.

## Durability and recovery

Objects are authoritative. References are the mutable catalog. Indexes may be
deleted and rebuilt. Publication journals, garbage collection, replication, and
store verification commands remain future work.
