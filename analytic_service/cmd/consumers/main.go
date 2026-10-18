package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"analytic_service/internal/bootstrap"
	"analytic_service/internal/config"
	kafkaconsumer "analytic_service/internal/kafka"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	cfg, err := config.Load()
	if err != nil {
		log.Error("config", "err", err)
		os.Exit(1)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	conn, st, err := bootstrap.OpenStore(ctx, cfg)
	if err != nil {
		log.Error("bootstrap", "err", err)
		os.Exit(1)
	}
	defer conn.Close()

	consumers := kafkaconsumer.NewConsumers(cfg.KafkaBrokers, st, log)

	log.Info("analytic consumers starting", "brokers", cfg.KafkaBrokers)
	if err := consumers.Run(ctx); err != nil {
		log.Error("consumers stopped with error", "err", err)
		os.Exit(1)
	}
	log.Info("analytic consumers stopped")
}
