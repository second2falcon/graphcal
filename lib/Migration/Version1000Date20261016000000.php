<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261016000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('graphcal_calendars')) {
			$t = $schema->createTable('graphcal_calendars');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('graph_id', Types::STRING, ['notnull' => true, 'length' => 512]);
			// Mailbox the calendar lives in: '' = the user's own, otherwise the owner's address (shared/delegated).
			$t->addColumn('owner', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
			$t->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('nc_calendar_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('is_shared', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('can_edit', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('delta_link', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'graphcal_cal_user');
			$t->addIndex(['nc_calendar_id'], 'graphcal_cal_nc');
		}

		if (!$schema->hasTable('graphcal_events')) {
			$t = $schema->createTable('graphcal_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('nc_calendar_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('nc_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('graph_id', Types::STRING, ['notnull' => true, 'length' => 512]);
			$t->addColumn('change_key', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('is_series', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			// JSON: what was last synced (per-component hashes, own PARTSTAT, exdates) to diff outbound changes.
			$t->addColumn('snapshot', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['nc_calendar_id', 'nc_uri'], 'graphcal_ev_nc');
			$t->addIndex(['graph_id'], 'graphcal_ev_graph');
		}

		return $schema;
	}
}
