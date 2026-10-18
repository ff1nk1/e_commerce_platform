package bootstrap

import (
	"context"
	"fmt"
	"path/filepath"

	chclient "analytic_service/internal/clickhouse"
	"analytic_service/internal/config"
	"analytic_service/internal/store"

	"github.com/ClickHouse/clickhouse-go/v2/lib/driver"
)

// OpenStore ensures the ClickHouse database exists, connects, runs migrations,
// and returns a ready Store. Caller must Close the connection.
func OpenStore(ctx context.Context, cfg config.Config) (driver.Conn, *store.Store, error) {
	if err := chclient.EnsureDatabase(ctx, cfg); err != nil {
		return nil, nil, fmt.Errorf("ensure database: %w", err)
	}

	conn, err := chclient.Connect(ctx, cfg)
	if err != nil {
		return nil, nil, fmt.Errorf("clickhouse connect: %w", err)
	}

	migrationsDir := config.EnvOr("MIGRATIONS_DIR", "migrations")
	if abs, err := filepath.Abs(migrationsDir); err == nil {
		migrationsDir = abs
	}
	if err := chclient.Migrate(ctx, conn, migrationsDir); err != nil {
		_ = conn.Close()
		return nil, nil, fmt.Errorf("migrate: %w", err)
	}

	return conn, store.New(conn), nil
}
