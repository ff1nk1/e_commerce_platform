package httpapi

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"time"

	"analytic_service/internal/store"
)

// MetricsReader is the read port for analytics HTTP handlers (DIP).
type MetricsReader interface {
	Ping(ctx context.Context) error
	UsersMetrics(ctx context.Context, from, to *time.Time) (store.UsersMetrics, error)
	OrdersMetrics(ctx context.Context, from, to *time.Time) (store.OrdersMetrics, error)
	SearchMetrics(ctx context.Context, from, to *time.Time, limit int) (store.SearchMetrics, error)
	OrdersByStore(ctx context.Context, storeID *uint64, from, to *time.Time, limit int) (store.DimensionMetrics, error)
	OrdersByCategory(ctx context.Context, categoryID *uint64, from, to *time.Time, limit int) (store.DimensionMetrics, error)
}

type Server struct {
	store MetricsReader
	log   *slog.Logger
}

func NewServer(st MetricsReader, log *slog.Logger) *Server {
	if log == nil {
		log = slog.Default()
	}
	return &Server{store: st, log: log}
}

func (s *Server) Routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", s.handleHealthz)
	mux.HandleFunc("GET /analytics/users", s.handleUsers)
	mux.HandleFunc("GET /analytics/orders", s.handleOrders)
	mux.HandleFunc("GET /analytics/orders/stores", s.handleOrdersByStore)
	mux.HandleFunc("GET /analytics/orders/categories", s.handleOrdersByCategory)
	mux.HandleFunc("GET /analytics/search", s.handleSearch)
	return requireRole(mux, "admin", "analyst")
}

// requireRole is a defense-in-depth guard on the analytics API.
//
// The gateway already restricts /analytics to admin|analyst and enforces
// read-only analyst, but this service checks the trusted X-User-Role header in
// case a request arrives directly over the internal Docker network.
func requireRole(next http.Handler, allowed ...string) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/healthz" {
			next.ServeHTTP(w, r)

			return
		}

		role := r.Header.Get("X-User-Role")
		for _, a := range allowed {
			if role == a {
				next.ServeHTTP(w, r)

				return
			}
		}

		http.Error(w, "forbidden", http.StatusForbidden)
	})
}

// fail logs the underlying error server-side and returns a safe, generic body
// so internal details never leak to the client.
func (s *Server) fail(w http.ResponseWriter, r *http.Request, err error) {
	s.failStatus(w, r, http.StatusInternalServerError, err)
}

// failStatus is fail with an explicit status code (e.g. 503 for health checks).
func (s *Server) failStatus(w http.ResponseWriter, r *http.Request, status int, err error) {
	s.log.Error("http request failed",
		"method", r.Method,
		"path", r.URL.Path,
		"status", status,
		"err", err,
	)
	writeJSON(w, status, map[string]string{"error": "internal error"})
}

// badRequest logs the validation error and returns a safe, generic body so
// internal details never leak to the client.
func (s *Server) badRequest(w http.ResponseWriter, r *http.Request, err error) {
	s.log.Warn("http bad request",
		"method", r.Method,
		"path", r.URL.Path,
		"err", err,
	)
	writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid request"})
}

func (s *Server) handleHealthz(w http.ResponseWriter, r *http.Request) {
	if err := s.store.Ping(r.Context()); err != nil {
		s.failStatus(w, r, http.StatusServiceUnavailable, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *Server) handleUsers(w http.ResponseWriter, r *http.Request) {
	s.withRange(w, r, func(ctx context.Context, from, to *time.Time) (any, error) {
		return s.store.UsersMetrics(ctx, from, to)
	})
}

func (s *Server) handleOrders(w http.ResponseWriter, r *http.Request) {
	s.withRange(w, r, func(ctx context.Context, from, to *time.Time) (any, error) {
		return s.store.OrdersMetrics(ctx, from, to)
	})
}

func (s *Server) handleSearch(w http.ResponseWriter, r *http.Request) {
	from, to, ok := s.parseRangeOrBadRequest(w, r)
	if !ok {
		return
	}
	limit, err := parseLimit(r, 20)
	if err != nil {
		s.badRequest(w, r, err)
		return
	}
	m, err := s.store.SearchMetrics(r.Context(), from, to, limit)
	if err != nil {
		s.fail(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, m)
}

func (s *Server) handleOrdersByStore(w http.ResponseWriter, r *http.Request) {
	s.handleDimension(w, r, "store_id", s.store.OrdersByStore)
}

func (s *Server) handleOrdersByCategory(w http.ResponseWriter, r *http.Request) {
	s.handleDimension(w, r, "category_id", s.store.OrdersByCategory)
}

type dimensionQuery func(ctx context.Context, id *uint64, from, to *time.Time, limit int) (store.DimensionMetrics, error)

func (s *Server) handleDimension(w http.ResponseWriter, r *http.Request, idParam string, query dimensionQuery) {
	from, to, ok := s.parseRangeOrBadRequest(w, r)
	if !ok {
		return
	}
	dimID, err := parseOptionalUint64(r, idParam)
	if err != nil {
		s.badRequest(w, r, err)
		return
	}
	limit, err := parseLimit(r, 20)
	if err != nil {
		s.badRequest(w, r, err)
		return
	}
	m, err := query(r.Context(), dimID, from, to, limit)
	if err != nil {
		s.fail(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, m)
}

func (s *Server) withRange(w http.ResponseWriter, r *http.Request, fn func(ctx context.Context, from, to *time.Time) (any, error)) {
	from, to, ok := s.parseRangeOrBadRequest(w, r)
	if !ok {
		return
	}
	m, err := fn(r.Context(), from, to)
	if err != nil {
		s.fail(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, m)
}

func (s *Server) parseRangeOrBadRequest(w http.ResponseWriter, r *http.Request) (from, to *time.Time, ok bool) {
	from, to, err := parseRange(r)
	if err != nil {
		s.badRequest(w, r, err)
		return nil, nil, false
	}
	return from, to, true
}

func parseLimit(r *http.Request, def int) (int, error) {
	raw := r.URL.Query().Get("limit")
	if raw == "" {
		return def, nil
	}
	n, err := strconv.Atoi(raw)
	if err != nil || n < 1 || n > 100 {
		return 0, fmt.Errorf("limit must be 1..100")
	}
	return n, nil
}

func parseOptionalUint64(r *http.Request, key string) (*uint64, error) {
	raw := r.URL.Query().Get(key)
	if raw == "" {
		return nil, nil
	}
	n, err := strconv.ParseUint(raw, 10, 64)
	if err != nil || n == 0 {
		return nil, fmt.Errorf("%s must be a positive integer", key)
	}
	return &n, nil
}

func parseRange(r *http.Request) (*time.Time, *time.Time, error) {
	q := r.URL.Query()
	var from, to *time.Time

	if raw := q.Get("from"); raw != "" {
		t, err := parseDate(raw)
		if err != nil {
			return nil, nil, err
		}
		from = &t
	}
	if raw := q.Get("to"); raw != "" {
		t, err := parseDate(raw)
		if err != nil {
			return nil, nil, err
		}
		isDateOnly := len(raw) == 10
		if isDateOnly {
			// exclusive end-of-day for YYYY-MM-DD
			exclusiveEndOfDay := t.Add(24 * time.Hour)
			t = exclusiveEndOfDay
		}
		to = &t
	}
	return from, to, nil
}

func parseDate(raw string) (time.Time, error) {
	isDateOnly := len(raw) == 10
	if isDateOnly {
		return time.ParseInLocation("2006-01-02", raw, time.UTC)
	}
	return time.Parse(time.RFC3339, raw)
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
