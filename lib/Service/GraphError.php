<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use RuntimeException;

class GraphError extends RuntimeException {
	public function __construct(
		public readonly int $status,
		public readonly string $graphCode,
		string $message,
	) {
		parent::__construct("Graph $status $graphCode: $message", $status);
	}
}
