// Command grimoire manages an embedded filesystem registry.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"

	"github.com/webong/grimoire/registry"
	"github.com/webong/grimoire/registry/fsstore"
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
		root := flags.String("store", ".grimoire", "registry directory")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		_, err := fsstore.Open(*root)
		return err
	case "put-artifact":
		flags, root, file := fileFlags("put-artifact")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		if *file == "" {
			return errors.New("-file is required")
		}
		reg, err := openRegistry(*root)
		if err != nil {
			return err
		}
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
		flags, root, file := fileFlags("import")
		if err := flags.Parse(args[1:]); err != nil {
			return err
		}
		if *file == "" {
			return errors.New("-file is required")
		}
		reg, err := openRegistry(*root)
		if err != nil {
			return err
		}
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
		root := flags.String("store", ".grimoire", "registry directory")
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
		reg, err := openRegistry(*root)
		if err != nil {
			return err
		}
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
		root := flags.String("store", ".grimoire", "registry directory")
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
		reg, err := openRegistry(*root)
		if err != nil {
			return err
		}
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
		root := flags.String("store", ".grimoire", "registry directory")
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
		reg, err := openRegistry(*root)
		if err != nil {
			return err
		}
		return reg.Materialize(ctx, resolution, *output)
	default:
		return usageError()
	}
}

func fileFlags(name string) (*flag.FlagSet, *string, *string) {
	flags := flag.NewFlagSet(name, flag.ContinueOnError)
	root := flags.String("store", ".grimoire", "registry directory")
	file := flags.String("file", "", "input file")
	return flags, root, file
}

func openRegistry(root string) (*registry.Registry, error) {
	store, err := fsstore.Open(root)
	if err != nil {
		return nil, err
	}
	return registry.Open(store)
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
