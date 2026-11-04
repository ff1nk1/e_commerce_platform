package httpapi

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestRequireRoleAllowsAnalyticsRoles(t *testing.T) {
	next := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	handler := requireRole(next, "admin", "analyst")

	for _, role := range []string{"admin", "analyst"} {
		req := httptest.NewRequest(http.MethodGet, "/analytics/orders", nil)
		req.Header.Set("X-User-Role", role)
		rec := httptest.NewRecorder()

		handler.ServeHTTP(rec, req)

		if rec.Code != http.StatusOK {
			t.Fatalf("role %q: want 200, got %d", role, rec.Code)
		}
	}
}

func TestRequireRoleForbidsUnknownAndMissingRoles(t *testing.T) {
	next := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	handler := requireRole(next, "admin", "analyst")

	for _, role := range []string{"user", "", "guest"} {
		req := httptest.NewRequest(http.MethodGet, "/analytics/orders", nil)
		if role != "" {
			req.Header.Set("X-User-Role", role)
		}
		rec := httptest.NewRecorder()

		handler.ServeHTTP(rec, req)

		if rec.Code != http.StatusForbidden {
			t.Fatalf("role %q: want 403, got %d", role, rec.Code)
		}
	}
}

func TestRequireRoleSkipsHealthz(t *testing.T) {
	next := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	handler := requireRole(next, "admin", "analyst")

	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	rec := httptest.NewRecorder()

	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("healthz: want 200, got %d", rec.Code)
	}
}
