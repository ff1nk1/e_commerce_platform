package config

import (
	"fmt"
	"os"
	"strings"
)

type Config struct {
	HTTPAddr string

	KafkaBrokers []string

	ClickHouseAddr     string
	ClickHouseDatabase string
	ClickHouseUser     string
	ClickHousePassword string
}

func Load() (Config, error) {
	cfg := Config{
		HTTPAddr:           EnvOr("HTTP_ADDR", ":8006"),
		KafkaBrokers:       SplitCSV(EnvOr("KAFKA_BROKERS", "kafka:29092")),
		ClickHouseAddr:     EnvOr("CLICKHOUSE_ADDR", "analytic_clickhouse:9000"),
		ClickHouseDatabase: EnvOr("CLICKHOUSE_DATABASE", "analytics"),
		ClickHouseUser:     EnvOr("CLICKHOUSE_USER", "default"),
		ClickHousePassword: strings.TrimSpace(os.Getenv("CLICKHOUSE_PASSWORD")),
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

// EnvOr returns the trimmed env value or fallback when unset/blank.
func EnvOr(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
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
