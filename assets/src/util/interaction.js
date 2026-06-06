/**
 * Shared user-interaction predicates for replay events.
 *
 * Replay sessions are only worth sending when they contain a real user
 * interaction. Previously Client (countClickEvents) and ErrorDetector
 * (inline `events.filter(e => e.type === 'click')`) each rolled their own
 * check; this is the single implementation both use.
 *
 * Current definition of "interaction" is a click event, preserving prior
 * behaviour. Extend INTERACTION_EVENT_TYPES if other event types should
 * qualify in future.
 */

/** @type {Set<string>} Replay event types that count as a user interaction. */
const INTERACTION_EVENT_TYPES = new Set(['click']);

/**
 * Count user-interaction events in a replay event array.
 *
 * @param {Array} events - Replay events
 * @returns {number} Number of interaction events
 */
export function countUserInteractions(events) {
    if (!Array.isArray(events)) {
        return 0;
    }
    return events.filter(event => event && INTERACTION_EVENT_TYPES.has(event.type)).length;
}

/**
 * Whether the event array contains at least one user interaction.
 *
 * @param {Array} events - Replay events
 * @returns {boolean}
 */
export function hasUserInteraction(events) {
    return countUserInteractions(events) > 0;
}
