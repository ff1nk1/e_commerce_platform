package kafka

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"sync"
	"testing"
	"time"

	"analytic_service/internal/store"

	"github.com/segmentio/kafka-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// fakeReader replays a fixed message list and records commits.
type fakeReader struct {
	mu       sync.Mutex
	msgs     []kafka.Message
	pos      int
	commits  [][]kafka.Message
	closed   bool
	commitCh chan struct{}
}

func newFakeReader(msgs []kafka.Message) *fakeReader {
	return &fakeReader{msgs: msgs, commitCh: make(chan struct{}, len(msgs)+1)}
}

func (f *fakeReader) FetchMessage(ctx context.Context) (kafka.Message, error) {
	f.mu.Lock()
	if f.pos >= len(f.msgs) {
		f.mu.Unlock()
		<-ctx.Done()
		return kafka.Message{}, ctx.Err()
	}
	m := f.msgs[f.pos]
	f.pos++
	f.mu.Unlock()
	return m, nil
}

func (f *fakeReader) CommitMessages(_ context.Context, msgs ...kafka.Message) error {
	f.mu.Lock()
	f.commits = append(f.commits, append([]kafka.Message(nil), msgs...))
	f.mu.Unlock()
	f.commitCh <- struct{}{}
	return nil
}

func (f *fakeReader) Close() error {
	f.closed = true
	return nil
}

func (f *fakeReader) committedOffsets() []int64 {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []int64
	for _, batch := range f.commits {
		for _, m := range batch {
			out = append(out, m.Offset)
		}
	}
	return out
}

type recordingStore struct {
	mu      sync.Mutex
	rows    []any
	inserts int
	err     error
}

func (r *recordingStore) record(v any) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.err != nil {
		return r.err
	}
	r.rows = append(r.rows, v)
	r.inserts++
	return nil
}

func (r *recordingStore) InsertUserRegistrations(_ context.Context, rows []store.UserRegistration) error {
	for range rows {
		if err := r.record("user"); err != nil {
			return err
		}
	}
	return nil
}

func (r *recordingStore) InsertOrderFunnelEvents(_ context.Context, rows []store.OrderFunnelEvent) error {
	for range rows {
		if err := r.record("order"); err != nil {
			return err
		}
	}
	return nil
}

func (r *recordingStore) InsertOrderLineItems(_ context.Context, rows []store.OrderLineItem) error {
	for range rows {
		if err := r.record("line"); err != nil {
			return err
		}
	}
	return nil
}

func (r *recordingStore) InsertProductDims(_ context.Context, rows []store.ProductDim) error {
	for range rows {
		if err := r.record("product"); err != nil {
			return err
		}
	}
	return nil
}

func (r *recordingStore) InsertSearchQueries(_ context.Context, rows []store.SearchQuery) error {
	for range rows {
		if err := r.record("search"); err != nil {
			return err
		}
	}
	return nil
}

func (r *recordingStore) LookupProductDim(_ context.Context, _ uint64) (store.ProductDim, bool, error) {
	return store.ProductDim{}, false, nil
}

func (r *recordingStore) count() int {
	r.mu.Lock()
	defer r.mu.Unlock()
	return len(r.rows)
}

func (r *recordingStore) setErr(err error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.err = err
}

func validUserMsg(offset int64, userID uint64) kafka.Message {
	return kafka.Message{
		Topic:     "user.events",
		Partition: 0,
		Offset:    offset,
		Value:     []byte(`{"event_type":"user.registered","user_id":` + itoa(userID) + `}`),
	}
}

func itoa(n uint64) string {
	if n == 0 {
		return "0"
	}
	var b [20]byte
	i := len(b)
	for n > 0 {
		i--
		b[i] = byte('0' + n%10)
		n /= 10
	}
	return string(b[i:])
}

func newTestConsumers(st EventStore, r messageReader, dlq DLQPublisher) *Consumers {
	c := NewConsumers(Config{
		Brokers:       []string{"localhost:9092"},
		BatchMaxRows:  100,
		FlushInterval: 10 * time.Millisecond,
		RetryBackoff:  time.Millisecond,
		DLQSuffix:     ".dlq",
	}, st, dlq, slog.New(slog.NewTextHandler(io.Discard, nil)))
	c.newRead = func(kafka.ReaderConfig) messageReader { return r }
	return c
}

// runReaderUntilCommit runs the reader loop and cancels once the first commit
// lands, so assertions see a fully accounted-for batch.
func runReaderUntilCommit(t *testing.T, c *Consumers, r *fakeReader, sink consumerSink) {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- c.runReader(ctx, "test-group", []string{"user.events"}, sink) }()

	select {
	case <-r.commitCh:
	case <-time.After(2 * time.Second):
		cancel()
		t.Fatal("timed out waiting for commit")
	}
	cancel()
	select {
	case err := <-done:
		require.NoError(t, err)
	case <-time.After(2 * time.Second):
		t.Fatal("reader did not stop after cancel")
	}
}

func TestRunReaderCommitsAfterFlush(t *testing.T) {
	t.Parallel()

	st := &recordingStore{}
	msg := validUserMsg(7, 1)
	r := newFakeReader([]kafka.Message{msg})
	c := newTestConsumers(st, r, &fakeDLQ{})
	sink := newUserSink(st, 100)

	runReaderUntilCommit(t, c, r, sink)

	assert.Equal(t, 1, st.count(), "row written before commit")
	assert.Equal(t, []int64{7}, r.committedOffsets())
	assert.True(t, r.closed)
}

func TestRunReaderRoutesPoisonPillToDLQ(t *testing.T) {
	t.Parallel()

	st := &recordingStore{}
	bad := kafka.Message{Topic: "user.events", Partition: 0, Offset: 9, Value: []byte(`{not json`)}
	good := validUserMsg(10, 2)
	r := newFakeReader([]kafka.Message{bad, good})
	dlq := &fakeDLQ{}
	c := newTestConsumers(st, r, dlq)
	sink := newUserSink(st, 100)

	runReaderUntilCommit(t, c, r, sink)

	require.Len(t, dlq.published, 1)
	assert.Equal(t, int64(9), dlq.published[0].Offset)
	require.Len(t, dlq.topics, 1)
	assert.Equal(t, "user.events.dlq", dlq.topics[0])

	// The poisoned offset is committed and the consumer moves on to the next message.
	assert.Equal(t, []int64{9, 10}, r.committedOffsets())
	assert.Equal(t, 1, st.count())
}

func TestRunReaderRetriesTransientError(t *testing.T) {
	t.Parallel()

	st := &recordingStore{}
	st.setErr(errors.New("clickhouse down"))
	r := newFakeReader([]kafka.Message{validUserMsg(1, 1)})
	dlq := &fakeDLQ{}
	c := newTestConsumers(st, r, dlq)
	sink := newUserSink(st, 100)

	// Recover the store after a short delay: the loop must retry the same message.
	go func() {
		time.Sleep(20 * time.Millisecond)
		st.setErr(nil)
	}()

	runReaderUntilCommit(t, c, r, sink)

	assert.Empty(t, dlq.published, "transient errors are not dead-lettered")
	assert.Equal(t, 1, st.count())
	assert.Equal(t, []int64{1}, r.committedOffsets())
}

func TestRunReaderDLQFailureIsRetried(t *testing.T) {
	t.Parallel()

	st := &recordingStore{}
	bad := kafka.Message{Topic: "user.events", Partition: 0, Offset: 3, Value: []byte(`{not json`)}
	r := newFakeReader([]kafka.Message{bad})
	dlq := &fakeDLQ{err: errors.New("dlq unavailable")}
	c := newTestConsumers(st, r, dlq)
	sink := newUserSink(st, 100)

	// Publish keeps failing, so no commit must happen and the loop keeps retrying.
	ctx, cancel := context.WithTimeout(context.Background(), 80*time.Millisecond)
	defer cancel()
	err := c.runReader(ctx, "test-group", []string{"user.events"}, sink)
	require.NoError(t, err)
	assert.Empty(t, r.committedOffsets(), "offset must not advance when the DLQ write fails")
	assert.True(t, r.closed)
}
