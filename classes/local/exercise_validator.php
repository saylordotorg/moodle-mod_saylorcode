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

namespace mod_saylorcode\local;

use local_saylorcode\local\runner\execution_request;
use local_saylorcode\local\runner\execution_state;
use local_saylorcode\local\runner\jobe_provider;
use local_saylorcode\local\runner\provider_interface;

/**
 * Runs an author's reference solution against their test cases.
 *
 * This is the check that stops a broken exercise reaching students. An author
 * cannot tell by reading whether an expected value is right; a wrong one looks
 * perfectly plausible on the page and then fails every learner who meets it.
 * Running the reference solution on the same runner students will use is the
 * only way to know (specification section 10.8).
 *
 * Comparison goes through output_comparator, the same path the student sees, so
 * a validation that passes cannot be contradicted at Check time.
 *
 * @package    mod_saylorcode
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exercise_validator {
    /** @var provider_interface The execution backend. */
    protected provider_interface $provider;

    /**
     * Build the validator.
     *
     * @param provider_interface|null $provider Backend, defaulting to the configured one.
     */
    public function __construct(?provider_interface $provider = null) {
        $this->provider = $provider ?? jobe_provider::create_from_config();
    }

    /**
     * Validate a reference solution against a set of test cases.
     *
     * @param string $profileid The runtime profile.
     * @param string $entryfilename The file the solution lives in.
     * @param string $solution The reference solution source.
     * @param array $cases Decoded test cases.
     * @return array A report: overall verdict, a summary line and per case results.
     */
    public function validate(string $profileid, string $entryfilename, string $solution, array $cases): array {
        if (trim($solution) === '') {
            return $this->report(false, get_string('validatenosolution', 'mod_saylorcode'), []);
        }

        if (empty($cases)) {
            return $this->report(false, get_string('validatenocases', 'mod_saylorcode'), []);
        }

        $files = [$entryfilename => $solution];
        $results = [];
        $passed = 0;

        foreach ($cases as $index => $case) {
            $request = new execution_request(
                bin2hex(random_bytes(16)),
                $profileid,
                execution_request::MODE_VALIDATION,
                $files,
                (string) ($case['stdin'] ?? '')
            );

            $response = $this->provider->execute($request);
            $export = $response->export_for_student();
            $state = $response->get_state();

            // A compile error is a property of the solution, not of one case,
            // so it stops the run rather than repeating the same message.
            if ($state === execution_state::COMPILE_ERROR) {
                return $this->report(
                    false,
                    get_string('validatecompileerror', 'mod_saylorcode'),
                    [],
                    $export['compileroutput']
                );
            }

            if (execution_state::is_platform_failure($state)) {
                return $this->report(false, get_string('validaterunnerdown', 'mod_saylorcode'), []);
            }

            $expected = (string) ($case['expected'] ?? '');
            $actual = $export['stdout'];
            $ok = $state === execution_state::COMPLETED && output_comparator::matches($actual, $expected);

            if ($ok) {
                $passed++;
            }

            $results[] = [
                'name' => (string) ($case['name'] ?? get_string('validatecaseunnamed', 'mod_saylorcode', $index + 1)),
                'passed' => $ok,
                'ispublic' => !empty($case['ispublic']),
                'expected' => $expected,
                'actual' => $actual,
                'state' => $state,
            ];
        }

        $total = count($results);
        $ok = $passed === $total;

        return $this->report(
            $ok,
            get_string(
                $ok ? 'validatepassed' : 'validatefailed',
                'mod_saylorcode',
                (object) ['passed' => $passed, 'total' => $total]
            ),
            $results
        );
    }

    /**
     * Shape a report.
     *
     * @param bool $ok Whether the solution satisfied every case.
     * @param string $summary A sentence an author can act on.
     * @param array $results Per case results.
     * @param string $compileroutput Sanitised compiler output, when relevant.
     * @return array
     */
    protected function report(bool $ok, string $summary, array $results, string $compileroutput = ''): array {
        return [
            'valid' => $ok,
            'summary' => $summary,
            'compileroutput' => $compileroutput,
            'results' => $results,
        ];
    }
}
