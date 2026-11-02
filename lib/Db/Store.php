<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Sync state: which Outlook calendar maps to which Nextcloud calendar, and which event to which object.
 * ponytail: plain arrays instead of Entity/QBMapper classes; two tables don't need an ORM.
 */
class Store {
	private const CAL = 'graphcal_calendars';
	private const EV = 'graphcal_events';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function calendars(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::CAL)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id');
		return $this->all($qb);
	}

	/** @return list<array<string,mixed>> enabled calendars that have a Nextcloud counterpart */
	public function syncedCalendars(string $userId): array {
		return array_values(array_filter($this->calendars($userId), fn ($c) => $c['enabled'] && $c['nc_calendar_id']));
	}

	public function calendar(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::CAL)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->all($qb)[0] ?? null;
	}

	/** The synced calendar behind a Nextcloud calendar id, or null when the calendar isn't ours. */
	public function calendarByNcId(int $ncCalendarId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::CAL)
			->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		return $this->all($qb)[0] ?? null;
	}

	/** @return list<string> */
	public function users(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from(self::CAL);
		return array_map(fn ($r) => (string)$r['user_id'], $this->all($qb));
	}

	/** Insert or refresh the calendar row for a Graph calendar; returns the row. */
	public function upsertCalendar(string $userId, string $graphId, string $owner, string $name, bool $isShared, bool $canEdit, bool $enableIfNew): array {
		$existing = array_values(array_filter($this->calendars($userId), fn ($c) => $c['graph_id'] === $graphId && $c['owner'] === $owner));
		$qb = $this->db->getQueryBuilder();
		if ($existing) {
			$qb->update(self::CAL)
				->set('name', $qb->createNamedParameter($name))
				->set('is_shared', $qb->createNamedParameter($isShared, IQueryBuilder::PARAM_BOOL))
				->set('can_edit', $qb->createNamedParameter($canEdit, IQueryBuilder::PARAM_BOOL))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($existing[0]['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return $this->calendar((int)$existing[0]['id']) ?? throw new \RuntimeException('calendar row vanished');
		}
		$qb->insert(self::CAL)->values([
			'user_id' => $qb->createNamedParameter($userId),
			'graph_id' => $qb->createNamedParameter($graphId),
			'owner' => $qb->createNamedParameter($owner),
			'name' => $qb->createNamedParameter($name),
			'is_shared' => $qb->createNamedParameter($isShared, IQueryBuilder::PARAM_BOOL),
			'can_edit' => $qb->createNamedParameter($canEdit, IQueryBuilder::PARAM_BOOL),
			'enabled' => $qb->createNamedParameter($enableIfNew, IQueryBuilder::PARAM_BOOL),
		])->executeStatement();
		return $this->calendar($qb->getLastInsertId()) ?? throw new \RuntimeException('calendar row vanished');
	}

	public function updateCalendar(int $id, array $fields): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::CAL)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		foreach ($fields as $k => $v) {
			$qb->set($k, $qb->createNamedParameter($v, is_bool($v) ? IQueryBuilder::PARAM_BOOL : (is_int($v) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		}
		$qb->executeStatement();
	}

	public function deleteCalendar(int $id): void {
		$cal = $this->calendar($id);
		if ($cal && $cal['nc_calendar_id']) {
			$this->deleteEventsOfCalendar((int)$cal['nc_calendar_id']);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::CAL)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function event(int $ncCalendarId, string $ncUri): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::EV)
			->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_uri', $qb->createNamedParameter($ncUri)));
		return $this->all($qb)[0] ?? null;
	}

	public function eventByGraphId(int $ncCalendarId, string $graphId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::EV)
			->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('graph_id', $qb->createNamedParameter($graphId)));
		return $this->all($qb)[0] ?? null;
	}

	/** @return list<array<string,mixed>> */
	public function seriesOfCalendar(int $ncCalendarId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::EV)
			->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_series', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		return $this->all($qb);
	}

	public function saveEvent(string $userId, int $ncCalendarId, string $ncUri, string $graphId, ?string $changeKey, bool $isSeries, array $snapshot): void {
		$row = $this->event($ncCalendarId, $ncUri);
		$qb = $this->db->getQueryBuilder();
		$values = [
			'graph_id' => $qb->createNamedParameter($graphId),
			'change_key' => $qb->createNamedParameter($changeKey),
			'is_series' => $qb->createNamedParameter($isSeries, IQueryBuilder::PARAM_BOOL),
			'snapshot' => $qb->createNamedParameter(json_encode($snapshot, JSON_THROW_ON_ERROR)),
		];
		if ($row) {
			$qb->update(self::EV)->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT)));
			foreach ($values as $k => $v) {
				$qb->set($k, $v);
			}
		} else {
			$qb->insert(self::EV)->values($values + [
				'user_id' => $qb->createNamedParameter($userId),
				'nc_calendar_id' => $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_INT),
				'nc_uri' => $qb->createNamedParameter($ncUri),
			]);
		}
		$qb->executeStatement();
	}

	public function deleteEvent(int $ncCalendarId, string $ncUri): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::EV)
			->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_uri', $qb->createNamedParameter($ncUri)))
			->executeStatement();
	}

	public function deleteEventsOfCalendar(int $ncCalendarId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::EV)->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function deleteUser(string $userId): void {
		foreach ([self::EV, self::CAL] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
		}
	}

	/** @return list<array<string,mixed>> */
	private function all(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static function (array $r): array {
			foreach (['enabled', 'is_shared', 'can_edit', 'is_series'] as $b) {
				if (array_key_exists($b, $r)) {
					$r[$b] = (bool)$r[$b];
				}
			}
			foreach (['id', 'nc_calendar_id'] as $i) {
				if (isset($r[$i])) {
					$r[$i] = (int)$r[$i];
				}
			}
			$r['owner'] ??= '';
			$r['owner'] = (string)$r['owner'];
			return $r;
		}, $rows);
	}
}
