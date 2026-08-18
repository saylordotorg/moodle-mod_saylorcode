<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_saylorcode\tests\fixtures;

use local_saylorcode\local\runner\execution_request;
use local_saylorcode\local\runner\execution_response;
use local_saylorcode\local\runner\execution_state;
use local_saylorcode\local\runner\health_result;
use local_saylorcode\local\runner\provider_interface;

/**
 * A runner provider that returns pre-arranged answers.
 *
 * Lets the grading and test evaluation logic be exercised without a sandbox
 * host, which keeps these tests fast and deterministic. The point of the
 * provider interface is that this substitution requires no production code to
 * know about it.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scripted_provider implements provider_interface {
    /** @var array Output to return for each stdin value received. */
    protected array $outputs;

    /** @var string State to report for every execution. */
    protected string $state;

    /** @var execution_request[] Every request this provider was given. */
    public array $requests = [];

    /**
     * Build the provider.
     *
     * @param array $outputs Map of stdin to the stdout it should produce.
     * @param string $state State to report for every execution.
     */
    public function __construct(array $outputs = [], string $state = execution_state::COMPLETED) {
        $this->outputs = $outputs;
        $this->state = $state;
    }

    /**
     * Provider name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'scripted';
    }

    /**
     * Health probe.
     *
     * @return health_result
     */
    public function get_health(): health_result {
        return new health_result(true, 'scripted', 0.0, ['java17-console']);
    }

    /**
     * Supported profiles.
     *
     * @return string[]
     */
    public function get_supported_profiles(): array {
        return ['java17-console'];
    }

    /**
     * Return the arranged answer for this request.
     *
     * @param execution_request $request The request.
     * @return execution_response
     */
    public function execute(execution_request $request): execution_response {
        $this->requests[] = $request;

        $stdin = $request->get_stdin();
        $stdout = $this->outputs[$stdin] ?? '';

        return new execution_response(
            $request->get_request_id(),
            $this->state,
            $stdout,
            '',
            '',
            [],
            0,
            0.0,
            0.01
        );
    }

    /**
     * Cancellation is not supported.
     *
     * @param string $requestid Ignored.
     * @return bool
     */
    public function cancel(string $requestid): bool {
        return false;
    }

    /**
     * Whether cancellation is supported.
     *
     * @return bool
     */
    public function supports_cancellation(): bool {
        return false;
    }
}
