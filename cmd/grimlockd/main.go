package main

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/base32"
	"encoding/binary"
	"encoding/json"
	"flag"
	"log"
	"net/http"
	"time"

	"github.com/akive/grimoire/subsystems/grimlock/catalogfs"
	"github.com/akive/grimoire/subsystems/grimlock/core"
	grimlocksqlite "github.com/akive/grimoire/subsystems/grimlock/storage/sqlite"
	"github.com/akive/grimoire/subsystems/grimlock/transport/httpapi"
	_ "modernc.org/sqlite"
)

func main() {
	listenAddress := flag.String("listen", "127.0.0.1:8090", "HTTP listen address")
	databasePath := flag.String("database", "grimoire.sqlite", "Illuminate-migrated SQLite database")
	catalogPath := flag.String("catalog", "examples/catalog", "directory containing resource manifests")
	flag.Parse()

	database, err := sql.Open("sqlite", *databasePath)
	if err != nil {
		log.Fatal(err)
	}
	defer database.Close()

	catalog := core.NewCatalog()
	if err := catalogfs.Load(catalog, *catalogPath); err != nil {
		log.Fatalf("load catalog: %v", err)
	}
	store, err := grimlocksqlite.New(database)
	if err != nil {
		log.Fatal(err)
	}
	if err := store.SyncCatalog(context.Background(), catalog); err != nil {
		log.Fatalf("sync catalog (has the Illuminate migration run?): %v", err)
	}
	executors := core.NewExecutorRegistry()
	if err := executors.Register("builtin.echo", executorFunc(func(_ context.Context, lease core.ExecutionLease) (json.RawMessage, error) {
		return lease.Arguments, nil
	})); err != nil {
		log.Fatal(err)
	}
	service, err := core.NewServiceWithStore(catalog, core.ReadOnlyPolicy{}, executors, newID, store)
	if err != nil {
		log.Fatal(err)
	}
	server, err := httpapi.New(service)
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("Grimlock listening on http://%s", *listenAddress)
	log.Fatal(http.ListenAndServe(*listenAddress, server))
}

type executorFunc func(context.Context, core.ExecutionLease) (json.RawMessage, error)

func (f executorFunc) Execute(ctx context.Context, lease core.ExecutionLease) (json.RawMessage, error) {
	return f(ctx, lease)
}

func newID(string) string {
	value := make([]byte, 16)
	binary.BigEndian.PutUint64(value[:8], uint64(time.Now().UnixMilli()))
	if _, err := rand.Read(value[8:]); err != nil {
		panic(err)
	}
	return base32.StdEncoding.WithPadding(base32.NoPadding).EncodeToString(value)
}
