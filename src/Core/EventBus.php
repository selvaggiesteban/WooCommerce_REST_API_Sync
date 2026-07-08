<?php
/**
 * Event Bus for Internal Communication
 *
 * @package WooCommerceApiSync\Core
 */

namespace WooCommerceApiSync\Core;

defined('ABSPATH') || exit;

/**
 * Simple event dispatcher
 */
class EventBus
{
    /**
     * Event listeners
     *
     * @var array<string, array[]>
     */
    private array $listeners = [];

    /**
     * Event history for debugging
     *
     * @var array[]
     */
    private array $history = [];

    /**
     * Add an event listener
     *
     * @param string $event Event name
     * @param callable $callback Callback function
     * @param int $priority Priority (lower = earlier)
     */
    public function on(string $event, callable $callback, int $priority = 10): void
    {
        $this->listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];

        // Sort by priority
        usort($this->listeners[$event], function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
    }

    /**
     * Remove an event listener
     *
     * @param string $event Event name
     * @param callable $callback Callback function to remove
     */
    public function off(string $event, callable $callback): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        $this->listeners[$event] = array_filter(
            $this->listeners[$event],
            fn($listener) => $listener['callback'] !== $callback
        );
    }

    /**
     * Emit an event
     *
     * @param string $event Event name
     * @param mixed $data Event data
     * @return mixed Final data after all listeners
     */
    public function emit(string $event, mixed $data = null): mixed
    {
        // Record history
        $this->history[] = [
            'event' => $event,
            'data' => $data,
            'time' => microtime(true),
        ];

        // Keep only last 100 events
        if (count($this->history) > 100) {
            $this->history = array_slice($this->history, -100);
        }

        if (!isset($this->listeners[$event])) {
            return $data;
        }

        foreach ($this->listeners[$event] as $listener) {
            $result = call_user_func($listener['callback'], $data);
            
            // If listener returns non-null, use that as new data
            if ($result !== null) {
                $data = $result;
            }
        }

        return $data;
    }

    /**
     * Check if event has listeners
     *
     * @param string $event Event name
     * @return bool
     */
    public function has_listeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /**
     * Get all listeners for an event
     *
     * @param string $event Event name
     * @return array
     */
    public function get_listeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    /**
     * Get event history
     *
     * @param int $limit Number of events to return
     * @return array
     */
    public function get_history(int $limit = 50): array
    {
        return array_slice($this->history, -$limit);
    }

    /**
     * Clear event history
     */
    public function clear_history(): void
    {
        $this->history = [];
    }

    /**
     * Remove all listeners
     */
    public function reset(): void
    {
        $this->listeners = [];
        $this->history = [];
    }
}
