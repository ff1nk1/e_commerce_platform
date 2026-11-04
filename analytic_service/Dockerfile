# syntax=docker/dockerfile:1

FROM golang:1.27.3-bookworm AS builder
WORKDIR /src

COPY go.mod go.sum ./
RUN go mod download

# Explicit paths only (no `COPY . .`): keeps .env and stray files out of the image.
COPY cmd/ ./cmd/
COPY internal/ ./internal/
RUN CGO_ENABLED=0 GOOS=linux go build -o /out/analytic-api ./cmd/api \
 && CGO_ENABLED=0 GOOS=linux go build -o /out/analytic-consumers ./cmd/consumers

FROM debian:bookworm-slim
WORKDIR /app

RUN useradd --system --uid 10001 --no-create-home appuser

COPY --from=builder /out/analytic-api /app/analytic-api
COPY --from=builder /out/analytic-consumers /app/analytic-consumers
COPY migrations /app/migrations

ENV MIGRATIONS_DIR=/app/migrations
USER appuser

EXPOSE 8006
CMD ["/app/analytic-api"]
