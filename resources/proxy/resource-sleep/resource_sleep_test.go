package resource_sleep

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestWaitsForWakeAndForwardsOriginalRequest(t *testing.T) {
	var mu sync.Mutex
	phases := make([]string, 0)
	polls := 0
	callback := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer test-token" {
			t.Error("missing management token")
		}
		body, _ := io.ReadAll(r.Body)
		if strings.Contains(string(body), "customer-secret") {
			t.Error("forwarded customer data to management API")
		}
		mu.Lock()
		defer mu.Unlock()
		phases = append(phases, string(body))
		polls++
		fmt.Fprintf(w, `{"ready":%v}`, polls > 1)
	}))
	defer callback.Close()
	next := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		if r.Method != "POST" || r.Header.Get("Authorization") != "customer-secret" || string(body) != "original-body" || r.URL.Path != "/submit" {
			t.Error("original request changed")
		}
		w.WriteHeader(201)
	})
	handler, err := New(context.Background(), next, &Config{CallbackURL: callback.URL, Token: "test-token", TimeoutSeconds: 3}, "test")
	if err != nil {
		t.Fatal(err)
	}
	request := httptest.NewRequest("POST", "/submit", strings.NewReader("original-body"))
	request.Header.Set("Authorization", "customer-secret")
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, request)
	if response.Code != 201 {
		t.Fatalf("status %d", response.Code)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(phases) < 3 || !strings.Contains(phases[len(phases)-1], `"phase":"end"`) {
		t.Fatalf("missing lifecycle callbacks: %v", phases)
	}
}

func TestManagementFailureDoesNotForwardRequest(t *testing.T) {
	callback := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(403) }))
	defer callback.Close()
	handler, err := New(context.Background(), http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { t.Error("forwarded without authorization") }), &Config{CallbackURL: callback.URL, Token: "token", TimeoutSeconds: 1}, "test")
	if err != nil {
		t.Fatal(err)
	}
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, httptest.NewRequest("GET", "/", nil))
	if response.Code != 503 {
		t.Fatalf("status %d", response.Code)
	}
}

func TestAwakeRequestSurvivesTemporaryManagementOutage(t *testing.T) {
	outage := false
	callback := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if outage {
			w.WriteHeader(503)
			return
		}
		fmt.Fprint(w, `{"ready":true}`)
	}))
	defer callback.Close()
	forwarded := 0
	handler, err := New(context.Background(), http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { forwarded++; w.WriteHeader(204) }), &Config{CallbackURL: callback.URL, Token: "token", TimeoutSeconds: 1}, "test")
	if err != nil {
		t.Fatal(err)
	}
	handler.ServeHTTP(httptest.NewRecorder(), httptest.NewRequest("GET", "/", nil))
	outage = true
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, httptest.NewRequest("GET", "/", nil))
	if response.Code != 204 || forwarded != 2 {
		t.Fatalf("awake request blocked: status=%d forwarded=%d", response.Code, forwarded)
	}
}

func TestHeartbeatFailureDoesNotCancelAnActiveStream(t *testing.T) {
	heartbeat := make(chan struct{}, 1)
	callback := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		if strings.Contains(string(body), `"phase":"heartbeat"`) {
			select {
			case heartbeat <- struct{}{}:
			default:
			}
			w.WriteHeader(503)
			return
		}
		fmt.Fprint(w, `{"ready":true}`)
	}))
	defer callback.Close()
	handler, err := New(context.Background(), http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-heartbeat:
		case <-time.After(time.Second):
			t.Fatal("missing heartbeat")
		}
		select {
		case <-r.Context().Done():
			t.Error("management outage cancelled stream")
		case <-time.After(25 * time.Millisecond):
		}
		w.WriteHeader(204)
	}), &Config{CallbackURL: callback.URL, Token: "token", TimeoutSeconds: 1}, "test")
	if err != nil {
		t.Fatal(err)
	}
	handler.(*middleware).heartbeat = 5 * time.Millisecond
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, httptest.NewRequest("GET", "/", nil))
	if response.Code != 204 {
		t.Fatalf("status %d", response.Code)
	}
}
