package kafka

import (
	"errors"
	"fmt"
)

// permanentError marks a message that can never be processed successfully
// (malformed payload / decode failure). Retrying it only blocks the consumer,
// so consumers divert it to the DLQ and commit past it instead.
type permanentError struct{ err error }

func (e *permanentError) Error() string { return e.err.Error() }
func (e *permanentError) Unwrap() error { return e.err }

// Permanent wraps err so retry loops treat it as non-retryable.
func Permanent(err error) error {
	if err == nil {
		return nil
	}
	return &permanentError{err: err}
}

// Permanentf builds a permanent (non-retryable) error from a format string.
func Permanentf(format string, args ...any) error {
	return Permanent(fmt.Errorf(format, args...))
}

// isPermanent reports whether err is a poison-pill error.
func isPermanent(err error) bool {
	var pe *permanentError
	return errors.As(err, &pe)
}
