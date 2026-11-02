// Package resourcesleep holds requests in Traefik while Coolify resumes a retained deployment.
// It sends only an opaque request identity to Coolify, never customer URLs, headers or bodies.
package resource_sleep

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"time"
)

type Config struct {
	CallbackURL    string `json:"callbackURL,omitempty"`
	Token          string `json:"token,omitempty"`
	TimeoutSeconds int    `json:"timeoutSeconds,omitempty"`
}

func CreateConfig() *Config { return &Config{TimeoutSeconds: 180} }

type middleware struct {
	next        http.Handler
	callbackURL string
	token       string
	timeout     time.Duration
	client      *http.Client
}

func New(ctx context.Context, next http.Handler, config *Config, name string) (http.Handler, error) {
	endpoint, err := url.Parse(config.CallbackURL)
	if err != nil || endpoint.Host == "" || (endpoint.Scheme != "https" && endpoint.Scheme != "http") || endpoint.User != nil || config.Token == "" || config.TimeoutSeconds < 1 || config.TimeoutSeconds > 240 {
		return nil, fmt.Errorf("invalid resource sleep callback configuration")
	}
	return &middleware{next: next, callbackURL: config.CallbackURL, token: config.Token, timeout: time.Duration(config.TimeoutSeconds) * time.Second,
		client: &http.Client{Timeout: 5 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error { return http.ErrUseLastResponse }}}, nil
}

func (m *middleware) call(ctx context.Context, id, phase string) (bool, error) {
	body, err := json.Marshal(map[string]string{"request_id": id, "phase": phase})
	if err != nil {
		return false, err
	}
	req, err := http.NewRequestWithContext(ctx, "POST", m.callbackURL, bytes.NewReader(body))
	if err != nil {
		return false, err
	}
	req.Header.Set("Authorization", "Bearer "+m.token)
	req.Header.Set("Content-Type", "application/json")
	response, err := m.client.Do(req)
	if err != nil {
		return false, err
	}
	defer response.Body.Close()
	if response.StatusCode != 200 {
		return false, fmt.Errorf("lifecycle callback rejected")
	}
	var state struct {
		Ready bool `json:"ready"`
	}
	if err := json.NewDecoder(response.Body).Decode(&state); err != nil {
		return false, err
	}
	return state.Ready, nil
}

func (m *middleware) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	random := make([]byte, 16)
	if _, err := rand.Read(random); err != nil {
		http.Error(w, "Resource unavailable", 503)
		return
	}
	id := hex.EncodeToString(random)
	defer func() {
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		m.call(ctx, id, "end")
	}()
	ctx, cancel := context.WithTimeout(r.Context(), m.timeout)
	defer cancel()
	for {
		ready, err := m.call(ctx, id, "begin")
		if err != nil {
			http.Error(w, "Resource unavailable", 503)
			return
		}
		if ready {
			break
		}
		select {
		case <-ctx.Done():
			http.Error(w, "Resource is still waking. Please retry.", 503)
			return
		case <-time.After(time.Second):
		}
	}
	// Keep streams and WebSockets awake for their entire lifetime, including after an upgrade.
	forwardContext, stopForwarding := context.WithCancel(r.Context())
	defer stopForwarding()
	done := make(chan struct{})
	defer close(done)
	go func() {
		ticker := time.NewTicker(20 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-done:
				return
			case <-r.Context().Done():
				return
			case <-ticker.C:
				if _, err := m.call(forwardContext, id, "heartbeat"); err != nil {
					stopForwarding()
					return
				}
			}
		}
	}()
	m.next.ServeHTTP(w, r.WithContext(forwardContext))
}
