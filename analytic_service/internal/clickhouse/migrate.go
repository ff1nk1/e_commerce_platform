package clickhouse

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/ClickHouse/clickhouse-go/v2/lib/driver"
)

const migrationsTable = "schema_migrations"

// Migrate applies every .sql file in migrationsDir exactly once, recording each
// applied file name in the schema_migrations table. Files are applied in
// lexicographic order; already-applied files are skipped. Each file must hold a
// single statement (ClickHouse rejects multi-statements, and there is no
// SQL-aware splitter here); bodies are expected to be idempotent
// (CREATE/ALTER ... IF NOT EXISTS) so the first tracked run over an existing
// unversioned volume is safe.
func Migrate(ctx context.Context, conn driver.Conn, migrationsDir string) error {
	if err := ensureMigrationsTable(ctx, conn); err != nil {
		return err
	}

	applied, err := appliedVersions(ctx, conn)
	if err != nil {
		return err
	}

	entries, err := os.ReadDir(migrationsDir)
	if err != nil {
		return fmt.Errorf("read migrations dir: %w", err)
	}

	names := make([]string, 0, len(entries))
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		names = append(names, e.Name())
	}
	sort.Strings(names)

	for _, name := range names {
		if applied[name] {
			continue
		}
		body, err := os.ReadFile(filepath.Join(migrationsDir, name))
		if err != nil {
			return fmt.Errorf("read migration %s: %w", name, err)
		}
		if err := conn.Exec(ctx, string(body)); err != nil {
			return fmt.Errorf("exec migration %s: %w", name, err)
		}
		if err := recordVersion(ctx, conn, name); err != nil {
			return fmt.Errorf("record migration %s: %w", name, err)
		}
	}
	return nil
}

func ensureMigrationsTable(ctx context.Context, conn driver.Conn) error {
	q := fmt.Sprintf(`
		CREATE TABLE IF NOT EXISTS %s
		(
			version String,
			applied_at DateTime64(3, 'UTC')
		)
		ENGINE = ReplacingMergeTree
		ORDER BY (version)
	`, migrationsTable)
	if err := conn.Exec(ctx, q); err != nil {
		return fmt.Errorf("create %s: %w", migrationsTable, err)
	}
	return nil
}

func appliedVersions(ctx context.Context, conn driver.Conn) (map[string]bool, error) {
	rows, err := conn.Query(ctx, fmt.Sprintf("SELECT version FROM %s FINAL", migrationsTable))
	if err != nil {
		return nil, fmt.Errorf("query %s: %w", migrationsTable, err)
	}
	defer func() { _ = rows.Close() }()

	applied := make(map[string]bool)
	for rows.Next() {
		var v string
		if err := rows.Scan(&v); err != nil {
			return nil, fmt.Errorf("scan %s: %w", migrationsTable, err)
		}
		applied[v] = true
	}
	return applied, rows.Err()
}

func recordVersion(ctx context.Context, conn driver.Conn, version string) error {
	batch, err := conn.PrepareBatch(ctx, fmt.Sprintf("INSERT INTO %s (version, applied_at)", migrationsTable))
	if err != nil {
		return fmt.Errorf("prepare insert: %w", err)
	}
	if err := batch.Append(version, time.Now().UTC()); err != nil {
		return fmt.Errorf("append version: %w", err)
	}
	return batch.Send()
}
