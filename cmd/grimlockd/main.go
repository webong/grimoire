package main

import (
	"flag"
	"log"
	"net/http"

	"github.com/akive/grimoire/subsystems/grimlock/catalogfs"
	"github.com/akive/grimoire/subsystems/grimlock/core"
	"github.com/akive/grimoire/subsystems/grimlock/transport/registryhttp"
)

func main() {
	listenAddress := flag.String("listen", "127.0.0.1:8090", "HTTP listen address")
	catalogPath := flag.String("catalog", "examples/catalog", "directory containing resource manifests")
	flag.Parse()

	catalog := core.NewCatalog()
	if err := catalogfs.Load(catalog, *catalogPath); err != nil {
		log.Fatalf("load catalog: %v", err)
	}
	server, err := registryhttp.New(catalog)
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("Grimlock registry listening on http://%s", *listenAddress)
	log.Fatal(http.ListenAndServe(*listenAddress, server))
}
