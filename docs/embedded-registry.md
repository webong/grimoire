# Single-file registry format

Grimoire uses a DBMS-like model in one local binary file. The application ships
the `grimoire` executable, points it at a `.grim` file, and communicates through
commands and JSON. No database server or Go linkage is required.

| DBMS concept | Grimoire representation |
| --- | --- |
| Database | One `.grim` file |
| Record | Checksummed binary frame |
| Primary key | SHA-256 object digest |
| Table | Resource kind in a reference record |
| Transaction | Locked append plus `fsync` |
| Optimistic concurrency | Reference revision token |
| Snapshot | Resolution lockfile |
| Write-ahead history | Append-only reference records |
| Compaction | Planned rewrite of live records |

## Version 1 layout

The file begins with a 16-byte header:

```text
8 bytes  magic: GRIMDB01
4 bytes  big-endian format version
4 bytes  reserved
```

The remainder is an append-only sequence of frames:

```text
1 byte   record kind (object or reference)
8 bytes  big-endian payload length
32 bytes SHA-256(kind || payload)
N bytes  payload
```

Object payloads are stored byte-for-byte and indexed by their SHA-256 digest.
Reference payloads are JSON records containing an exact resource key, object
digest, publication status, and revision. On open, Grimoire scans the frames and
rebuilds its in-memory indexes; the latest valid record for a reference wins.

## Durability and recovery

Grimoire takes an exclusive advisory lock for the lifetime of an open database,
so two processes cannot write the same file concurrently. Every appended frame
is flushed with `fsync` before an operation succeeds.

The frame header includes its payload length and checksum. If startup finds an
incomplete final frame, it truncates that tail to the last complete boundary.
A checksum failure inside a complete frame is reported as corruption rather
than silently discarded.

Object writes are idempotent because equal bytes have the same digest.
Reference updates use compare-and-swap revisions, preventing a stale operation
from replacing a newer catalog state.

## Limits and planned work

Version 1 accepts object payloads up to 512 MiB and reference payloads up to
1 MiB. The append-only format retains old reference records and currently scans
the file at startup. Compaction, a persisted index, full-store verification,
backup tooling, and a persistent stdio protocol are the next storage/runtime
milestones.
