<?php

/**
 * Schedule Manager
 *
 * @author Fabrizio Branca
 * @since 2014-08-14
 */
class Aoe_Scheduler_Model_ScheduleManager
{
    public const XML_PATH_HISTORY_MAXNO = 'system/cron/maxNoOfSuccessfulTasks';
    public const CACHE_KEY_SCHEDULER_LASTRUNS = 'cron_lastruns';

    /**
     * Mark missed schedule records by changing status
     *
     * @return $this
     * @throws Exception
     */
    public function skipMissedSchedules()
    {
        $schedules = Mage::getModel('cron/schedule')->getCollection()
            ->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_PENDING)
            ->addFieldToFilter('scheduled_at', ['lt' => strftime('%Y-%m-%d %H:%M:%S', time())])
            ->addOrder('scheduled_at', 'DESC')
            ->load();

        Mage::getSingleton('cron/schedule')->getResource()->beginTransaction(TRUE);
        try {
            $seenJobs = [];
            foreach ($schedules as $key => $schedule) {
                /* @var Aoe_Scheduler_Model_Schedule $schedule */
                if (isset($seenJobs[$schedule->getJobCode()])) {
                    $schedule
                        ->setMessages('Multiple tasks with the same job code were piling up. Skipping execution of duplicates.')
                        ->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_SKIP_PILINGUP);
                    $updated = $schedule->getResource()->trySetJobStatusAtomic(
                        $schedule->getId(),
                        $schedule->getStatus(),
                        Aoe_Scheduler_Model_Schedule::STATUS_PENDING
                    );
                    if ($updated) {
                        $schedule->save();
                    }
                } else {
                    $seenJobs[$schedule->getJobCode()] = 1;
                }
            }
            Mage::getSingleton('cron/schedule')->getResource()->commit();
        } catch (Exception $e) {
            Mage::getSingleton('cron/schedule')->getResource()->rollBack();
            throw $e;
        }

        return $this;
    }

    /**
     * Get pending schedules
     *
     *
     * @return Mage_Cron_Model_Resource_Schedule_Collection
     */
    public function getPendingSchedules(array $whitelist = [], array $blacklist = [])
    {
        $pendingSchedules = Mage::getModel('cron/schedule')->getCollection()
            ->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_PENDING)
            ->addFieldToFilter('scheduled_at', ['lt' => strftime('%Y-%m-%d %H:%M:%S', time())])
            ->addOrder('scheduled_at', 'ASC');

        $whitelist = array_filter(array_map('trim', $whitelist));
        if (!empty($whitelist)) {
            $pendingSchedules->addFieldToFilter('job_code', ['in' => $whitelist]);
        }

        $blacklist = array_filter(array_map('trim', $blacklist));
        if (!empty($blacklist)) {
            $pendingSchedules->addFieldToFilter('job_code', ['nin' => $blacklist]);
        }

        return $pendingSchedules;
    }

    /**
     * Get job for task marked as always
     *
     * (Instead of reusing existing one - which results in loosing the history - create a new one every time)
     *
     * @param $jobCode
     */
    public function getScheduleForAlwaysJob($jobCode, $reason=null): \Aoe_Scheduler_Model_Schedule|false
    {
        $processManager = Mage::getModel('aoe_scheduler/processManager'); /* @var $processManager Aoe_Scheduler_Model_ProcessManager */
        if (!$processManager->isJobCodeRunning($jobCode)) {
            $ts = strftime('%Y-%m-%d %H:%M:00', time());
            $schedule = Mage::getModel('cron/schedule'); /* @var $schedule Aoe_Scheduler_Model_Schedule */
            $schedule
                ->setScheduledReason($reason ?: Aoe_Scheduler_Model_Schedule::REASON_ALWAYS)
                ->setJobCode($jobCode)
                ->setStatus(Aoe_Scheduler_Model_Schedule::STATUS_RUNNING)
                ->setCreatedAt($ts)
                ->setScheduledAt($ts)
                ->save();
            return $schedule;
        }
        return false;
    }

    /**
     * Delete duplicate crons
     *
     * @throws Exception
     */
    public function deleteDuplicates()
    {
        $cron_schedule = Mage::getSingleton('core/resource')->getTableName('cron_schedule');
        $conn = Mage::getSingleton('core/resource')->getConnection('core_read');

        // TODO: Direct sql is not nice. We can do better... :)
        $results = $conn->fetchAll("
			SELECT
				GROUP_CONCAT(schedule_id) AS ids,
				CONCAT(job_code, scheduled_at) AS jobkey,
				count(*) AS qty
			FROM {$cron_schedule}
			WHERE status = '" . Aoe_Scheduler_Model_Schedule::STATUS_PENDING . "'
			GROUP BY jobkey
			HAVING qty > 1;
		");
        foreach ($results as $row) {
            $ids = explode(',', $row['ids']);
            $removeIds = array_slice($ids, 1);
            foreach ($removeIds as $id) {
                Mage::getModel('cron/schedule')->load($id)->delete();
            }
        }
    }

    /**
     * Generate cron schedule.
     * Rewrites the original method to remove duplicates afterwards (that exists because of a bug)
     *
     * @return $this
     */
    public function generateSchedules()
    {
        /**
         * check if schedule generation is needed
         */
        $lastRun = Mage::app()->loadCache(Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT);
        if ($lastRun > time() - Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_SCHEDULE_GENERATE_EVERY) * 60) {
            return $this;
        }

        $startTime = microtime(true);

        /* @var $jobs Aoe_Scheduler_Model_Resource_Job_Collection */
        $jobs = Mage::getSingleton('aoe_scheduler/job')->getCollection();
        $jobs->setActiveOnly(true);
        foreach ($jobs as $job) {
            /* @var Aoe_Scheduler_Model_Job $job */
            $this->generateSchedulesForJob($job);
        }

        /**
         * save time schedules generation was ran with no expiration
         */
        Mage::app()->saveCache(time(), Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT, ['crontab'], null);

        $this->deleteDuplicates();

        if ($logFile = Mage::getStoreConfig('system/cron/logFile')) {
            $history = Mage::getModel('cron/schedule')->getCollection()
                ->setPageSize(1)
                ->setOrder('scheduled_at', 'desc')
                ->load();

            $newestSchedule = $history->getFirstItem(); /* @var $newestSchedule Aoe_Scheduler_Model_Schedule */

            $duration = microtime(true) - $startTime;
            Mage::log('Generated schedule. Newest task is scheduled at "' . $newestSchedule->getScheduledAt() . '". (Duration: ' . round($duration, 2) . ' sec)', null, $logFile);
        }

        return $this;
    }

    /**
     * Flushed all future pending schedules.
     *
     * @param string $jobCode
     * @return $this
     */
    public function flushSchedules($jobCode = null)
    {
        /* @var $pendingSchedules Mage_Cron_Model_Resource_Schedule_Collection */
        $pendingSchedules = Mage::getModel('cron/schedule')->getCollection()
            ->addFieldToFilter('status', Aoe_Scheduler_Model_Schedule::STATUS_PENDING)
            ->addFieldToFilter('scheduled_at', ['gt' => strftime('%Y-%m-%d %H:%M:%S', time())])
            ->addOrder('scheduled_at', 'ASC');
        if (!empty($jobCode)) {
            $pendingSchedules->addFieldToFilter('job_code', $jobCode);
        }
        foreach ($pendingSchedules as $key => $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $schedule->delete();
        }
        Mage::app()->saveCache(0, Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT, ['crontab'], null);
        return $this;
    }

    /**
     * Delete all schedules
     *
     * @return $this
     */
    public function deleteAll()
    {
        /* @var $schedules Mage_Cron_Model_Resource_Schedule_Collection */
        $schedules = Mage::getModel('cron/schedule')->getCollection();
        foreach ($schedules as $key => $schedule) { /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $schedule->delete();
        }
        Mage::app()->saveCache(0, Mage_Cron_Model_Observer::CACHE_KEY_LAST_SCHEDULE_GENERATE_AT, ['crontab'], null);
        return $this;
    }

    /**
     * Generate jobs for config information
     *
     *
     * @return $this
     */
    public function generateSchedulesForJob(Aoe_Scheduler_Model_Job $job)
    {
        if (!$job->canBeScheduled()) {
            return $this;
        }

        $exists = [];
        foreach ($this->getPendingSchedules([$job->getJobCode()], []) as $schedule) {
            /* @var Aoe_Scheduler_Model_Schedule $schedule */
            $exists[$schedule->getJobCode() . '/' . $schedule->getScheduledAt()] = 1;
        }

        $now = time();
        $scheduleAheadFor = Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_SCHEDULE_AHEAD_FOR)*60;
        $timeAhead = $now + $scheduleAheadFor;

        $schedule = Mage::getModel('cron/schedule'); /* @var $schedule Aoe_Scheduler_Model_Schedule */
        $schedule->initializeFromJob($job);
        $schedule->setScheduledReason(Aoe_Scheduler_Model_Schedule::REASON_GENERATESCHEDULES);

        for ($time = $now + 60; $time < $timeAhead; $time += 60) {
            $ts = strftime('%Y-%m-%d %H:%M:00', $time);
            if (!empty($exists[$job->getJobCode().'/'.$ts])) {
                // already scheduled
                continue;
            }
            if (!$schedule->trySchedule($time)) {
                // time does not match cron expression
                continue;
            }
            $schedule->unsScheduleId()->save();
        }

        return $this;
    }

    /**
     * Clean up the history of tasks
     * This override deals with custom states added in Aoe_Scheduler
     *
     * @return $this
     */
    public function cleanup()
    {
        // check if history cleanup is needed
        $lastCleanup = Mage::app()->loadCache(Mage_Cron_Model_Observer::CACHE_KEY_LAST_HISTORY_CLEANUP_AT);
        if ($lastCleanup > time() - Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_CLEANUP_EVERY) * 60) {
            return $this;
        }

        $startTime = microtime(true);

        $history = Mage::getModel('cron/schedule')->getCollection()
            ->addFieldToFilter('status', ['nin' => [Aoe_Scheduler_Model_Schedule::STATUS_PENDING, Aoe_Scheduler_Model_Schedule::STATUS_RUNNING]])
            ->load();

        $historyLifetimes = [Aoe_Scheduler_Model_Schedule::STATUS_KILLED =>          Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_SUCCESS)*60, Aoe_Scheduler_Model_Schedule::STATUS_DISAPPEARED =>     Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE)*60, Aoe_Scheduler_Model_Schedule::STATUS_DIDNTDOANYTHING => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_SUCCESS)*60, Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS =>         Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_SUCCESS)*60, Aoe_Scheduler_Model_Schedule::STATUS_REPEAT =>          Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_SUCCESS)*60, Aoe_Scheduler_Model_Schedule::STATUS_MISSED =>          Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE)*60, Aoe_Scheduler_Model_Schedule::STATUS_SKIP_PILINGUP =>   Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE)*60, Aoe_Scheduler_Model_Schedule::STATUS_ERROR =>           Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE)*60, Aoe_Scheduler_Model_Schedule::STATUS_DIED =>            Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE)*60, Aoe_Scheduler_Model_Schedule::STATUS_SKIP_OTHERJOBRUNNING => Mage::getStoreConfig(Mage_Cron_Model_Observer::XML_PATH_HISTORY_FAILURE)*60];

        $now = time();
        foreach ($history->getIterator() as $record) { /* @var $record Aoe_Scheduler_Model_Schedule */
            if (isset($historyLifetimes[$record->getStatus()])) {
                if (strtotime($record->getExecutedAt()) < $now - $historyLifetimes[$record->getStatus()]) {
                    $record->delete();
                }
            }
        }

        // save time history cleanup was ran with no expiration
        Mage::app()->saveCache(time(), Mage_Cron_Model_Observer::CACHE_KEY_LAST_HISTORY_CLEANUP_AT, ['crontab'], null);


        // delete successful tasks (beyond the configured max number of tasks to keep)
        $maxNo = Mage::getStoreConfig(self::XML_PATH_HISTORY_MAXNO);
        if ($maxNo) {
            $history = Mage::getModel('cron/schedule')->getCollection()
                ->addFieldToFilter(
                    ['status'],
                    [['eq' => Aoe_Scheduler_Model_Schedule::STATUS_SUCCESS], ['eq' => Aoe_Scheduler_Model_Schedule::STATUS_REPEAT]]
                )
                ->setOrder('finished_at', 'desc')
                ->load();
            $counter = [];
            foreach ($history->getIterator() as $record) { /* @var $record Aoe_Scheduler_Model_Schedule */
                $jobCode = $record->getJobCode();
                if (!isset($counter[$jobCode])) {
                    $counter[$jobCode] = 0;
                }
                $counter[$jobCode]++;
                if ($counter[$jobCode] > $maxNo) {
                    $record->delete();
                }
            }
        }

        if ($logFile = Mage::getStoreConfig('system/cron/logFile')) {
            $duration = microtime(true) - $startTime;
            Mage::log('History cleanup (Duration: ' . round($duration, 2) . ' sec)', null, $logFile);
        }

        return $this;
    }

    /**
     * Log run
     */
    public function logRun()
    {
        $lastRuns = Mage::app()->loadCache(self::CACHE_KEY_SCHEDULER_LASTRUNS);
        $lastRuns = explode(',', $lastRuns);
        $lastRuns[] = time();
        $lastRuns = array_slice($lastRuns, -100);
        Mage::app()->saveCache(implode(',', $lastRuns), self::CACHE_KEY_SCHEDULER_LASTRUNS, ['crontab'], null);
    }

    /**
     * Create some statistics based on self::CACHE_KEY_SCHEDULER_LASTRUNS
     */
    public function getMeasuredCronInterval()
    {
        $lastRuns = Mage::app()->loadCache(self::CACHE_KEY_SCHEDULER_LASTRUNS);
        $lastRuns = array_values(array_filter(explode(',', $lastRuns)));
        if (count($lastRuns) < 3) {
            // not enough data points
            return false;
        }
        $gaps = [];
        foreach ($lastRuns as $index => $run) {
            if ($index > 0) {
                $gaps[$index] = intval($lastRuns[$index]) - intval($lastRuns[$index-1]);
            }
        }
        return ['average' => round((array_sum($gaps) / count($gaps)) / 60, 2), 'max' => round(max($gaps) / 60, 2), 'min' => round(min($gaps) / 60, 2), 'count' => count($gaps), 'last' => end($lastRuns)];
    }
}
