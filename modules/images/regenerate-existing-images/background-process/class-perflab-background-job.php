<?php
/**
 * Background job class.
 *
 * @since n.e.x.t
 * @package performance-lab
 */

/**
 * Class Perflab_Background_Job.
 *
 * Manage and run the background jobs.
 *
 * Following fields are being stored for a background job.
 *
 * 1. Job ID: Identifies the job; stored as the term ID.
 * 2. Job identifier: Unique identifier for different types of jobs. Stored as term meta `perflab_job_identifier`.
 * 3. Job data: Job related data. Stored in term meta in serialised format `perflab_job_data`.
 * 4. Job status: Background job status like running, failed etc. Stored as term meta `perflab_job_status`.
 * 5. Job errors: Errors related to a job. Stored as term meta in serialized format `perflab_job_errors`.
 * 6. Job attempts: Number of times this job has been attempted to run after failure. Stored as term meta `perflab_job_attempts`.
 * 7. Job lock: Timestamp in seconds at which the job has started. Stored as term meta `perflab_job_lock`.
 * 8. Job completed at: Timestamp at which the job has been marked as completed. Stored as term meta `perflab_job_completed_at`.
 *
 * @since n.e.x.t
 */
class Perflab_Background_Job {
	/**
	 * Meta key for storing job name/action.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const META_KEY_JOB_IDENTIFIER = 'perflab_job_identifier';

	/**
	 * Meta key for storing job data.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const META_KEY_JOB_DATA = 'perflab_job_data';

	/**
	 * Meta key for storing number of attempts for a job.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const META_KEY_JOB_ATTEMPTS = 'perflab_job_attempts';

	/**
	 * Meta key for storing job lock.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const META_KEY_JOB_LOCK = 'perflab_job_lock';

	/**
	 * Meta key for storing job errors.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const META_KEY_JOB_ERRORS = 'perflab_job_errors';

	/**
	 * Job status meta key.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const META_KEY_JOB_STATUS = 'perflab_job_status';

	/**
	 * Timestamp at which the job was completed.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const META_KEY_JOB_COMPLETED_AT = 'perflab_job_completed_at';

	/**
	 * Job status for queued jobs.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const JOB_STATUS_QUEUED = 'queued';

	/**
	 * Job status for running jobs.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const JOB_STATUS_RUNNING = 'running';

	/**
	 * Job status for partially executed jobs.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const JOB_STATUS_PARTIAL = 'partial';

	/**
	 * Job status for completed jobs.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const JOB_STATUS_COMPLETE = 'completed';

	/**
	 * Job status for failed jobs.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const JOB_STATUS_FAILED = 'failed';

	/**
	 * Job ID.
	 *
	 * @since n.e.x.t
	 * @var int
	 */
	private $id;

	/**
	 * Job identifier.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	private $identifier;

	/**
	 * Job data.
	 *
	 * @since n.e.x.t
	 * @var array|null
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 *
	 * @param int $job_id Job ID. Technically this is the ID of a term in the 'background_job' taxonomy.
	 */
	public function __construct( $job_id ) {
		$this->id = absint( $job_id );
	}

	/**
	 * Returns the id for the job.
	 *
	 * @since n.e.x.t
	 *
	 * @return int Job ID. Technically this is a term id for `background_job` taxonomy.
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Retrieves the job data.
	 *
	 * @since n.e.x.t
	 *
	 * @return array|null Job data.
	 */
	public function get_data() {
		$this->data = get_term_meta( $this->id, self::META_KEY_JOB_DATA, true );

		return $this->data;
	}

	/**
	 * Retrieves the job identifier.
	 *
	 * This identifier will be used in custom action triggered by background process
	 * runner. Action will be like `perflab_job_{job_identifier}`.
	 * Consumer code can hook onto this action to perform necessary task for the job.
	 *
	 * @since n.e.x.t
	 *
	 * @return string Job identifier.
	 */
	public function get_identifier() {
		$this->identifier = get_term_meta( $this->id, self::META_KEY_JOB_IDENTIFIER, true );

		return $this->identifier;
	}

	/**
	 * Determines whether a job should run for current request or not.
	 *
	 * It determines by checking if job is already running or completed.
	 * It also checks if max number of attempts have been attempted for the job.
	 * For any such condition, it will return false, else true.
	 *
	 * @since n.e.x.t
	 *
	 * @return bool Flag to tell whether this job should run or not.
	 */
	public function should_run() {
		// If job doesn't exist or completed, return false.
		if ( ! $this->exists() || $this->is_completed() ) {
			return false;
		}

		if ( $this->is_running() ) {
			return false;
		}

		/**
		 * Number of attempts to try to run a job.
		 *
		 * Repeated attempts may be required to run a failed job. Default 3 attempts.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $attempts Number of attempts allowed for a job to run. Default 3.
		 */
		$max_attempts = (int) apply_filters( 'perflab_job_max_attempts_allowed', 3 );

		// If number of attempts have been exhausted, return false.
		if ( $this->get_attempts() >= $max_attempts ) {
			return false;
		}

		return true;
	}

	/**
	 * Sets the status of the current job.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $status Status to set for current job.
	 * @return bool Returns true if status is updated, false otherwise.
	 */
	public function set_status( $status ) {
		$valid_statuses = array(
			self::JOB_STATUS_COMPLETE,
			self::JOB_STATUS_FAILED,
			self::JOB_STATUS_QUEUED,
			self::JOB_STATUS_RUNNING,
			self::JOB_STATUS_PARTIAL,
		);

		if ( in_array( $status, $valid_statuses, true ) ) {
			update_term_meta( $this->id, self::META_KEY_JOB_STATUS, $status );

			return true;
		}

		return false;
	}

	/**
	 * Marks the job as completed.
	 *
	 * 1. Mark the job status as completed.
	 * 2. It will also save the timestamp (in seconds) at which the job was completed.
	 *
	 * @since n.e.x.t
	 */
	public function complete() {
		$this->set_status( self::JOB_STATUS_COMPLETE );
		// If job is complete, set the timestamp at which it was completed.
		update_term_meta( $this->id, self::META_KEY_JOB_COMPLETED_AT, time() );
	}

	/**
	 * Marks the job as queued.
	 *
	 * @since n.e.x.t
	 */
	public function queued() {
		$this->set_status( self::JOB_STATUS_QUEUED );
	}

	/**
	 * Retrieves the job status from its meta information.
	 *
	 * @since n.e.x.t
	 *
	 * @return string Job status.
	 */
	public function get_status() {
		$status = (string) get_term_meta( $this->id, self::META_KEY_JOB_STATUS, true );

		if ( empty( $status ) ) {
			$status = self::JOB_STATUS_QUEUED;
		}

		return $status;
	}

	/**
	 * Returns the number of attempts executed for a job.
	 *
	 * @since n.e.x.t
	 *
	 * @return int Number of times the job has been attempted.
	 */
	public function get_attempts() {
		$attempts = get_term_meta( $this->id, self::META_KEY_JOB_ATTEMPTS, true );

		return (int) $attempts;
	}

	/**
	 * Set the start time of job.
	 * It tells at what point of time the job has been started.
	 *
	 * @since n.e.x.t
	 *
	 * @param int $time Timestamp (in seconds) at which job has been started.
	 */
	public function lock( $time = null ) {
		$time = empty( $time ) ? time() : $time;
		update_term_meta( $this->id, self::META_KEY_JOB_LOCK, $time );
		$this->set_status( self::JOB_STATUS_RUNNING );
	}

	/**
	 * Get the timestamp (in seconds) when the job was started.
	 *
	 * @since n.e.x.t
	 *
	 * @return int Start time of the job.
	 */
	public function get_start_time() {
		$time = get_term_meta( $this->id, self::META_KEY_JOB_LOCK, true );

		return absint( $time );
	}

	/**
	 * Unlocks the process.
	 *
	 * Mark job status as partial as the unlock implies that job has run
	 * atleast partially.
	 *
	 * @since n.e.x.t
	 */
	public function unlock() {
		delete_term_meta( $this->id, self::META_KEY_JOB_LOCK );
		$this->set_status( self::JOB_STATUS_PARTIAL );
	}

	/**
	 * Checks if the background job is completed.
	 *
	 * @since n.e.x.t
	 *
	 * @return bool Whether the job is completed.
	 */
	public function is_completed() {
		return ( self::JOB_STATUS_COMPLETE === $this->get_status() );
	}

	/**
	 * Checks that the job term exist.
	 *
	 * @since n.e.x.t
	 *
	 * @return bool Whether the term exists or not.
	 */
	public function exists() {
		return (bool) term_exists( $this->id, PERFLAB_BACKGROUND_JOB_TAXONOMY_SLUG );
	}

	/**
	 * Checks if the job is running.
	 *
	 * If the job lock is present, it means job is running.
	 *
	 * @since n.e.x.t
	 *
	 * @return bool Whether job is currently running.
	 */
	public function is_running() {
		return ( self::JOB_STATUS_RUNNING === $this->get_status() );
	}
}
