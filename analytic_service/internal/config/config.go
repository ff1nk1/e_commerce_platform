package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"
)

type Config struct {
	HTTPAddr string

	KafkaBrokers []string

	ClickHouseAddr     string
	ClickHouseDatabase string
	ClickHouseUser     string
	ClickHousePassword string

	// ClickHouse async_insert session settings (applied on Connect with DB).
	ClickHouseAsyncInsert               uint64
	ClickHouseWaitForAsyncInsert        uint64
	ClickHouseAsyncInsertBusyTimeoutMs  uint64
	ClickHouseAsyncInsertMaxDataSize    uint64
	ClickHouseAsyncInsertMaxQueryNumber uint64
}

func Load() (Config, error) {
	cfg := Config{
		HTTPAddr:           EnvOr("HTTP_ADDR", ":8006"),
		KafkaBrokers:       SplitCSV(EnvOr("KAFKA_BROKERS", "kafka:29092")),
		ClickHouseAddr:     EnvOr("CLICKHOUSE_ADDR", "analytic_clickhouse:9000"),
		ClickHouseDatabase: EnvOr("CLICKHOUSE_DATABASE", "analytics"),
		ClickHouseUser:     EnvOr("CLICKHOUSE_USER", "default"),
		ClickHousePassword: strings.TrimSpace(os.Getenv("CLICKHOUSE_PASSWORD")),

		ClickHouseAsyncInsert:               EnvUint("CLICKHOUSE_ASYNC_INSERT", 1),
		ClickHouseWaitForAsyncInsert:        EnvUint("CLICKHOUSE_WAIT_FOR_ASYNC_INSERT", 0),
		ClickHouseAsyncInsertBusyTimeoutMs:  EnvUint("CLICKHOUSE_ASYNC_INSERT_BUSY_TIMEOUT_MS", 1000),
		ClickHouseAsyncInsertMaxDataSize:    EnvUint("CLICKHOUSE_ASYNC_INSERT_MAX_DATA_SIZE", 10485760),
		ClickHouseAsyncInsertMaxQueryNumber: EnvUint("CLICKHOUSE_ASYNC_INSERT_MAX_QUERY_NUMBER", 100),
	}

	if len(cfg.KafkaBrokers) == 0 {
		return Config{}, fmt.Errorf("KAFKA_BROKERS is empty")
	}
	if cfg.ClickHouseAddr == "" {
		return Config{}, fmt.Errorf("CLICKHOUSE_ADDR is empty")
	}
	if cfg.ClickHousePassword == "" {
		return Config{}, fmt.Errorf("CLICKHOUSE_PASSWORD is required")
	}
	return cfg, nil
}

// DefaultAsyncInsertConfig returns production defaults for async_insert settings
// (used when building Config without Load, e.g. tests that only set addr/password).
func DefaultAsyncInsertConfig() (
	asyncInsert, waitFor, busyTimeoutMs, maxDataSize, maxQueryNumber uint64,
) {
	return 1, 0, 1000, 10485760, 100
}

// EnvOr returns the trimmed env value or fallback when unset/blank.
func EnvOr(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
}

// EnvUint parses an unsigned integer from env, or returns fallback when unset/invalid.
func EnvUint(key string, fallback uint64) uint64 {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return fallback
	}
	n, err := strconv.ParseUint(v, 10, 64)
	if err != nil {
		return fallback
	}
	return n
}

// SplitCSV splits a comma-separated list and trims empty parts.
func SplitCSV(s string) []string {
	parts := strings.Split(s, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			out = append(out, p)
		}
	}
	return out
}
