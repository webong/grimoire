// Command grimoire manages a self-contained single-file registry.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"

	"github.com/webong/grimoire/internal/registry"
	"github.com/webong/grimoire/internal/registry/packstore"
)

func main() {
	if err := run(context.Background(), os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "grimoire:", err)
		os.Exit(1)
	}
}

func run(ctx context.Context, args []string) error {
	if len(args) == 0 {
		return usageError()
	}
	switch args[0] {
	case "init":
		flags := flag.NewFlagSet("init", flag.ContinueOnError)
		database := databaseFlag(flags)
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		store, err := packstore.Open(*database)
		if err != nil {
			return err
		}
		return store.Close()
	case "put-artifact":
		flags, database, file := fileFlags("put-artifact")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		if *file == "" {
			return errors.New("-file is required")
		}
		reg, closer, err := openRegistry(*database)
		if err != nil {
			return err
		}
		defer closer.Close()
		raw, err := os.ReadFile(*file)
		if err != nil {
			return err
		}
		digest, err := reg.PutArtifact(ctx, raw)
		if err != nil {
			return err
		}
		fmt.Println(digest)
		return nil
	case "import":
		flags, database, file := fileFlags("import")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		if *file == "" {
			return errors.New("-file is required")
		}
		reg, closer, err := openRegistry(*database)
		if err != nil {
			return err
		}
		defer closer.Close()
		raw, err := os.ReadFile(*file)
		if err != nil {
			return err
		}
		reference, err := reg.ImportManifest(ctx, raw)
		if err != nil {
			return err
		}
		return writeJSON(os.Stdout, reference)
	case "publish", "retire":
		flags := flag.NewFlagSet(args[0], flag.ContinueOnError)
		database := databaseFlag(flags)
		kind := flags.String("kind", "", "resource kind")
		name := flags.String("name", "", "resource name")
		version := flags.String("version", "", "resource version")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		ref, err := parseRef(*kind, *name, *version)
		if err != nil {
			return err
		}
		reg, closer, err := openRegistry(*database)
		if err != nil {
			return err
		}
		defer closer.Close()
		var reference registry.Reference
		if args[0] == "publish" {
			reference, err = reg.Publish(ctx, ref)
		} else {
			reference, err = reg.Retire(ctx, ref)
		}
		if err != nil {
			return err
		}
		return writeJSON(os.Stdout, reference)
	case "resolve":
		flags := flag.NewFlagSet("resolve", flag.ContinueOnError)
		database := databaseFlag(flags)
		requestPath := flags.String("request", "", "resolution request JSON")
		output := flags.String("out", "", "lockfile output; stdout when omitted")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		if *requestPath == "" {
			return errors.New("-request is required")
		}
		raw, err := os.ReadFile(*requestPath)
		if err != nil {
			return err
		}
		var request registry.ResolveRequest
		if err := json.Unmarshal(raw, &request); err != nil {
			return err
		}
		reg, closer, err := openRegistry(*database)
		if err != nil {
			return err
		}
		defer closer.Close()
		resolution, err := reg.Resolve(ctx, request)
		if err != nil {
			return err
		}
		if *output == "" {
			return writeJSON(os.Stdout, resolution)
		}
		encoded, err := json.MarshalIndent(resolution, "", "  ")
		if err != nil {
			return err
		}
		return os.WriteFile(*output, append(encoded, '\n'), 0o644)
	case "materialize":
		flags := flag.NewFlagSet("materialize", flag.ContinueOnError)
		database := databaseFlag(flags)
		lockfile := flags.String("resolution", "", "resolution lockfile")
		output := flags.String("out", "", "sandbox destination")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		if *lockfile == "" || *output == "" {
			return errors.New("-resolution and -out are required")
		}
		raw, err := os.ReadFile(*lockfile)
		if err != nil {
			return err
		}
		var resolution registry.Resolution
		if err := json.Unmarshal(raw, &resolution); err != nil {
			return err
		}
		reg, closer, err := openRegistry(*database)
		if err != nil {
			return err
		}
		defer closer.Close()
		return reg.Materialize(ctx, resolution, *output)
	default:
		return usageError()
	}
}

func fileFlags(name string) (*flag.FlagSet, *string, *string) {
	flags := flag.NewFlagSet(name, flag.ContinueOnError)
	database := databaseFlag(flags)
	file := flags.String("file", "", "input file")
	return flags, database, file
}

func databaseFlag(flags *flag.FlagSet) *string {
	return flags.String("db", "grimoire.grim", "Grimoire database file")
}

func openRegistry(path string) (*registry.Registry, io.Closer, error) {
	store, err := packstore.Open(path)
	if err != nil {
		return nil, nil, err
	}
	reg, err := registry.Open(store)
	if err != nil {
		store.Close()
		return nil, nil, err
	}
	return reg, store, nil
}

func parseRef(kind, name, version string) (registry.Ref, error) {
	ref := registry.Ref{Kind: registry.ResourceKind(kind), Name: name, Version: version}
	switch ref.Kind {
	case registry.KindRuntime, registry.KindSkill, registry.KindTool, registry.KindPolicy:
	default:
		return registry.Ref{}, errors.New("-kind must be Runtime, Skill, Tool, or Policy")
	}
	if err := ref.Validate(ref.Kind); err != nil {
		return registry.Ref{}, err
	}
	return ref, nil
}

func writeJSON(file *os.File, value any) error {
	encoder := json.NewEncoder(file)
	encoder.SetIndent("", "  ")
	return encoder.Encode(value)
}

func usageError() error {
	return errors.New("usage: grimoire <init|put-artifact|import|publish|retire|resolve|materialize> [flags]")
}
