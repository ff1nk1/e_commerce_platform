package testutil

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"

	chclient "analytic_service/internal/clickhouse"
	"analytic_service/internal/config"
	"analytic_service/internal/store"

	"github.com/ClickHouse/clickhouse-go/v2/lib/driver"
	"github.com/stretchr/testify/require"
)

const defaultClickHousePassword = "analytic_local_secret"

// OpenTestStore connects to ClickHouse (default localhost:19000 / analytics_test).
// Skips the test when ClickHouse is unreachable.
func OpenTestStore(t *testing.T) (*store.Store, driver.Conn) {
	t.Helper()

	asyncInsert, _, busyTimeoutMs, maxDataSize, maxQueryNumber := config.DefaultAsyncInsertConfig()
	cfg := config.Config{
		ClickHouseAddr:     config.EnvOr("CLICKHOUSE_ADDR", "localhost:19000"),
		ClickHouseDatabase: config.EnvOr("CLICKHOUSE_DATABASE", "analytics_test"),
		ClickHouseUser:     config.EnvOr("CLICKHOUSE_USER", "default"),
		ClickHousePassword: config.EnvOr("CLICKHOUSE_PASSWORD", defaultClickHousePassword),
		// Same async_insert path as prod, but wait for flush so insert→SELECT tests are deterministic.
		ClickHouseAsyncInsert:               asyncInsert,
		ClickHouseWaitForAsyncInsert:        1,
		ClickHouseAsyncInsertBusyTimeoutMs:  busyTimeoutMs,
		ClickHouseAsyncInsertMaxDataSize:    maxDataSize,
		ClickHouseAsyncInsertMaxQueryNumber: maxQueryNumber,
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if err := chclient.EnsureDatabase(ctx, cfg); err != nil {
		t.Skipf("clickhouse unavailable at %s: %v", cfg.ClickHouseAddr, err)
	}

	conn, err := chclient.Connect(ctx, cfg)
	if err != nil {
		t.Skipf("clickhouse connect failed at %s: %v", cfg.ClickHouseAddr, err)
	}

	migrationsDir := MigrationsDir(t)
	require.NoError(t, chclient.Migrate(ctx, conn, migrationsDir))

	t.Cleanup(func() {
		_ = conn.Close()
	})

	return store.New(conn), conn
}

// TruncateAnalytics clears fact tables between cases.
func TruncateAnalytics(t *testing.T, conn driver.Conn) {
	t.Helper()
	ctx := context.Background()
	tables := []string{
		"order_funnel_events",
		"order_line_items",
		"product_dim",
		"user_registrations",
		"search_queries",
	}
	for _, table := range tables {
		require.NoError(t, conn.Exec(ctx, fmt.Sprintf("TRUNCATE TABLE IF EXISTS %s", table)))
	}
}

// MigrationsDir resolves analytic_service/migrations relative to this source file.
func MigrationsDir(t *testing.T) string {
	t.Helper()
	if v := os.Getenv("MIGRATIONS_DIR"); v != "" {
		abs, err := filepath.Abs(v)
		require.NoError(t, err)
		return abs
	}
	_, file, _, ok := runtime.Caller(0)
	require.True(t, ok)
	dir := filepath.Join(filepath.Dir(file), "..", "..", "migrations")
	abs, err := filepath.Abs(dir)
	require.NoError(t, err)
	return abs
}

func KafkaBrokers() []string {
	parts := config.SplitCSV(config.EnvOr("KAFKA_BROKERS", "localhost:9092"))
	if len(parts) == 0 {
		return []string{"localhost:9092"}
	}
	return parts
}
