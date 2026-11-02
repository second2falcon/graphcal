<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use RuntimeException;

/** The user has to sign in to Microsoft again (refresh token expired or revoked). */
class ReauthRequired extends RuntimeException {
}
