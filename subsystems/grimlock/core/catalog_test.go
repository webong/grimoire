package core_test

import (
	"testing"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
)

func TestCatalogRegistersManagedClientDeviceAndConnector(t *testing.T) {
	catalog := core.NewCatalog()
	client := api.Client{
		APIVersion: api.APIVersion, Kind: api.KindClient,
		Metadata: api.Metadata{Name: "host-app-web", Version: "1.0.0"},
		Spec:     api.ClientSpec{ClientKind: "web"},
	}
	if err := catalog.RegisterClient(client); err != nil {
		t.Fatal(err)
	}
	clientRef := api.ResourceRef{Kind: api.KindClient, Name: "host-app-web", Version: "1.0.0"}
	device := api.Device{
		APIVersion: api.APIVersion, Kind: api.KindDevice,
		Metadata: api.Metadata{Name: "work-browser", Version: "1.0.0"},
		Spec:     api.DeviceSpec{ClientRef: &clientRef, DeviceKind: "browser", Endpoints: []api.Endpoint{{Name: "cdp", Protocol: "cdp", URL: "http://127.0.0.1:9222"}}},
	}
	if err := catalog.RegisterDevice(device); err != nil {
		t.Fatal(err)
	}
	deviceRef := api.ResourceRef{Kind: api.KindDevice, Name: "work-browser", Version: "1.0.0"}
	connector := api.Connector{
		APIVersion: api.APIVersion, Kind: api.KindConnector,
		Metadata: api.Metadata{Name: "browser-mcp", Version: "1.0.0"},
		Spec:     api.ConnectorSpec{Protocol: "mcp", Transport: "http", Direction: "bidirectional", Status: "active", Endpoint: "https://mcp.example.test", AllowedOperations: []string{"browser.navigate"}, DeviceRef: &deviceRef},
	}
	if err := catalog.RegisterConnector(connector); err != nil {
		t.Fatal(err)
	}
	if _, err := catalog.Client(clientRef); err != nil {
		t.Fatal(err)
	}
	if _, err := catalog.Device(deviceRef); err != nil {
		t.Fatal(err)
	}
	if _, err := catalog.Connector(api.ResourceRef{Kind: api.KindConnector, Name: "browser-mcp", Version: "1.0.0"}); err != nil {
		t.Fatal(err)
	}
}
