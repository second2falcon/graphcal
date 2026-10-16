<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/** The "GraphCal" tab (named like the app admins installed), in both Administration and Personal settings. */
class Section implements IIconSection {
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'graphcal';
	}

	public function getName(): string {
		return 'GraphCal';
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('graphcal', 'app-dark.svg');
	}
}
