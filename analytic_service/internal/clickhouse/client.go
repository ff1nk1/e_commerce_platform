package clickhouse

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"analytic_service/internal/config"

	ch "github.com/ClickHouse/clickhouse-go/v2"
	"github.com/ClickHouse/clickhouse-go/v2/lib/driver"
)

func options(cfg config.Config, withDB bool) *ch.Options {
	auth := ch.Auth{
		Username: cfg.ClickHouseUser,
		Password: cfg.ClickHousePassword,
	}
	if withDB {
		auth.Database = cfg.ClickHouseDatabase
	}
	opt := &ch.Options{
		Addr:             []string{cfg.ClickHouseAddr},
		Auth:             auth,
		DialTimeout:      10 * time.Second,
		ConnOpenStrategy: ch.ConnOpenInOrder,
	}
	// Server-side INSERT batching — only on DB connections (ingest + API).
	// EnsureDatabase (withDB=false) stays without these settings.
	if withDB {
		opt.Settings = ch.Settings{
			"async_insert":                  cfg.ClickHouseAsyncInsert,
			"wait_for_async_insert":         cfg.ClickHouseWaitForAsyncInsert,
			"async_insert_busy_timeout_ms":  cfg.ClickHouseAsyncInsertBusyTimeoutMs,
			"async_insert_max_data_size":    cfg.ClickHouseAsyncInsertMaxDataSize,
			"async_insert_max_query_number": cfg.ClickHouseAsyncInsertMaxQueryNumber,
		}
	}
	return opt
}

func Connect(ctx context.Context, cfg config.Config) (driver.Conn, error) {
	conn, err := ch.Open(options(cfg, true))
	if err != nil {
		return nil, fmt.Errorf("clickhouse open: %w", err)
	}

	pingCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if err := conn.Ping(pingCtx); err != nil {
		_ = conn.Close()
		return nil, fmt.Errorf("clickhouse ping: %w", err)
	}

	return conn, nil
}

// EnsureDatabase creates the database if missing (connects without DB first).
func EnsureDatabase(ctx context.Context, cfg config.Config) error {
	conn, err := ch.Open(options(cfg, false))
	if err != nil {
		return fmt.Errorf("clickhouse open for ensure db: %w", err)
	}
	defer func() { _ = conn.Close() }()

	q := fmt.Sprintf("CREATE DATABASE IF NOT EXISTS %s", quoteIdent(cfg.ClickHouseDatabase))
	if err := conn.Exec(ctx, q); err != nil {
		return fmt.Errorf("create database: %w", err)
	}
	return nil
}

func Migrate(ctx context.Context, conn driver.Conn, migrationsDir string) error {
	entries, err := os.ReadDir(migrationsDir)
	if err != nil {
		return fmt.Errorf("read migrations dir: %w", err)
	}

	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		path := filepath.Join(migrationsDir, e.Name())
		body, err := os.ReadFile(path)
		if err != nil {
			return fmt.Errorf("read migration %s: %w", e.Name(), err)
		}
		for _, stmt := range splitStatements(string(body)) {
			if err := conn.Exec(ctx, stmt); err != nil {
				return fmt.Errorf("exec migration %s: %w", e.Name(), err)
			}
		}
	}
	return nil
}

func splitStatements(sql string) []string {
	parts := strings.Split(sql, ";")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p == "" || strings.HasPrefix(p, "--") {
			continue
		}
		out = append(out, p)
	}
	return out
}

func quoteIdent(name string) string {
	return "`" + strings.ReplaceAll(name, "`", "") + "`"
}
