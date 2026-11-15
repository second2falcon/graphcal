<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use RuntimeException;

/** Entra ID refused a token request, or could not be reached. The message is Microsoft's and safe to show. */
class TokenError extends RuntimeException {
}
