<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use RuntimeException;

/** The event can't be represented in Microsoft 365; the message is shown to the user. */
class UnsupportedEvent extends RuntimeException {
}
