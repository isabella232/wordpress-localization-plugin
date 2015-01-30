<?php

namespace Smartling\Submissions;

use Psr\Log\LoggerInterface;
use Smartling\DbAl\EntityManagerAbstract;
use Smartling\DbAl\SmartlingToCMSDatabaseAccessWrapperInterface;
use Smartling\Helpers\QueryBuilder\Condition\Condition;
use Smartling\Helpers\QueryBuilder\Condition\ConditionBlock;
use Smartling\Helpers\QueryBuilder\Condition\ConditionBuilder;
use Smartling\Helpers\QueryBuilder\QueryBuilder;
use Smartling\Helpers\WordpressContentTypeHelper;

/**
 * Class SubmissionManager
 *
 * @package Smartling\Submissions
 */
class SubmissionManager extends EntityManagerAbstract {

	/**
	 * The table name
	 */
	const SUBMISSIONS_TABLE_NAME = '_smartling_submissions';

	/**
	 * @return array
	 */
	public function getSubmissionStatusLabels () {
		return SubmissionEntity::getSubmissionStatusLabels();
	}

	/**
	 * @return array
	 */
	public function getSubmissionStatuses () {
		return SubmissionEntity::$submissionStatuses;
	}

	/**
	 * @return string
	 */
	public function getDefaultSubmissionStatus () {
		return SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS;
	}

	/**
	 * @var WordpressContentTypeHelper
	 */
	private $helper;

	/**
	 * @return WordpressContentTypeHelper
	 */
	public function getHelper () {
		return $this->helper;
	}

	/**
	 * @var int
	 */
	private $pageSize;

	/**
	 * @return int
	 */
	public function getPageSize () {
		return $this->pageSize;
	}

	/**
	 * @param LoggerInterface                              $logger
	 * @param SmartlingToCMSDatabaseAccessWrapperInterface $dbal
	 */
	public function __construct (
		LoggerInterface $logger,
		SmartlingToCMSDatabaseAccessWrapperInterface $dbal,
		$pageSize
	) {
		parent::__construct( $logger, $dbal );
		$this->pageSize = (int) $pageSize;
	}

	/**
	 * @param $contentType
	 *
	 * @return bool
	 */
	private function validateContentType ( $contentType ) {
		return
			is_null( $contentType )
			|| in_array( $contentType, array_keys( WordpressContentTypeHelper::getReverseMap() ) );
	}

	/**
	 * @param      $query
	 *
	 * @return array of SubmissionEntity or empty array
	 */
	private function fetchData ( $query ) {
		$results = array ();

		$res = $this->dbal->fetch( $query );

		if ( is_array( $res ) ) {
			foreach ( $res as $row ) {
				$results[] = SubmissionEntity::fromArray( (array) $row, $this->logger );
			}
		}

		return $results;
	}

	/**
	 * Validates request
	 *
	 * @param $contentType
	 * @param $sortOptions
	 * @param $pageOptions
	 *
	 * @return bool
	 */
	private function validateRequest ( $contentType, $sortOptions, $pageOptions ) {
		$fSortOptionsAreValid = QueryBuilder::validateSortOptions(
			array_keys(
				SubmissionEntity::$fieldsDefinition
			),
			$sortOptions
		);

		$fPageOptionsValid = QueryBuilder::validatePageOptions( $pageOptions );

		$fContentTypeValid = $this->validateContentType( $contentType );

		$validRequest = $fContentTypeValid && $fPageOptionsValid && $fSortOptionsAreValid;

		return ( $validRequest === true );
	}

	/**
	 * @param null       $contentType
	 * @param null       $status
	 * @param array      $sortOptions
	 * @param null|array $pageOptions
	 *
	 * @param reference  $totalCount
	 *
	 * @return array of SubmissionEntity or empty array
	 *
	 * $sortOptions is an array that keys are SubmissionEntity fields and values are 'ASC' or 'DESC'
	 * or null if no sorting needed
	 *
	 * e.g.: array('submissionDate' => 'ASC', 'targetLocale' => 'DESC')
	 *
	 * $pageOptions is an array that has keys('page' and 'limit') for pagination output purposes purposes
	 * or null if no pagination needed
	 *
	 * e.g.: array('limit' => 20, 'page' => 1)
	 */
	public function getEntities (
		$contentType = null,
		$status = null,
		array $sortOptions = array (),
		$pageOptions = null,
		& $totalCount = 0
	) {
		$validRequest = $this->validateRequest( $contentType, $sortOptions, $pageOptions );

		$result = array ();

		if ( $validRequest ) {
			$dataQuery = $this->buildQuery( $contentType, $status, $sortOptions, $pageOptions );

			$countQuery = $this->buildCountQuery( $contentType, $status );

			$totalCount = $this->dbal->fetch( $countQuery );

			// extracting from result
			$totalCount = (int) $totalCount[0]->cnt;

			$result = $this->fetchData( $dataQuery );
		}

		return $result;
	}


	/**
	 * @param string      $searchText
	 * @param array       $searchFields
	 * @param null|string $contentType
	 * @param null|string $status
	 * @param array       $sortOptions
	 * @param null|array  $pageOptions
	 * @param int         $totalCount
	 *
	 * @return array
	 */
	public function search (
		$searchText,
		array $searchFields = array (),
		$contentType = null,
		$status = null,
		array $sortOptions = array (),
		$pageOptions = null,
		& $totalCount = 0
	) {

		$searchText = trim( $searchText );

		$totalCount = 0;

		$validRequest = ! empty( $searchFields ) && $this->validateRequest( $contentType, $sortOptions, $pageOptions );

		$result = array ();

		if ( $validRequest ) {

			$searchText = "%{$searchText}%";

			$block = ConditionBlock::getConditionBlock( ConditionBuilder::CONDITION_BLOCK_LEVEL_OPERATOR_OR );

			foreach ( $searchFields as $field ) {
				$block->addCondition(
					Condition::getCondition(
						ConditionBuilder::CONDITION_SIGN_LIKE,
						$field,
						array ( $searchText )
					)
				);
			}

			$dataQuery = $this->buildQuery( $contentType, $status, $sortOptions, $pageOptions, $block );

			$countQuery = $this->buildCountQuery( $contentType, $status, $block );

			$totalCount = $this->dbal->fetch( $countQuery );

			// extracting from result
			$totalCount = (int) $totalCount[0]->cnt;

			$result = $this->fetchData( $dataQuery );
		}

		return $result;
	}

	/**
	 * Gets SubmissionEntity from database by primary key
	 * alias to getEntities
	 *
	 * @param integer $id
	 *
	 * @return null|SubmissionEntity
	 */
	public function getEntityById ( $id ) {
		$query = $this->buildSelectQuery(
			self::SUBMISSIONS_TABLE_NAME,
			array_keys( SubmissionEntity::$fieldsDefinition ),
			array ( 'id' => (int) $id ),
			null,
			null
		);

		$obj = $this->fetchData( $query, false );

		if ( is_array( $obj ) && empty( $obj ) ) {
			$obj = null;
		}

		return $obj;
	}

	public function buildCountQuery ( $contentType, $status, ConditionBlock $baseCondition = null ) {

		$whereOptions = null;

		if ( ! is_null( $contentType ) || ! is_null( $status ) ) {
			$whereOptions = ConditionBlock::getConditionBlock( ConditionBuilder::CONDITION_BLOCK_LEVEL_OPERATOR_AND );
			if ( $baseCondition instanceof ConditionBlock ) {
				$whereOptions->addConditionBlock( $baseCondition );
			}

			if ( ! is_null( $contentType ) ) {
				$condition = Condition::getCondition( ConditionBuilder::CONDITION_SIGN_EQ, 'contentType',
					array ( $contentType ) );
				$whereOptions->addCondition( $condition );
			}

			if ( ! is_null( $status ) ) {
				$condition = Condition::getCondition( ConditionBuilder::CONDITION_SIGN_EQ, 'status',
					array ( $status ) );
				$whereOptions->addCondition( $condition );
			}
		}

		$query = QueryBuilder::buildSelectQuery(
			$this->dbal->completeTableName( self::SUBMISSIONS_TABLE_NAME ),
			array ( array ( 'COUNT(*)', 'cnt' ) ),
			$whereOptions,
			array (),
			null
		);

		$this->logger->info( $query );

		return $query;
	}

	/**
	 * Builds SELECT query for Submissions
	 *
	 * @param string         $contentType
	 * @param string         $status
	 * @param array|null     $sortOptions
	 * @param array|null     $pageOptions
	 *
	 * @param ConditionBlock $baseCondition
	 *
	 * @return string
	 */
	private function buildQuery (
		$contentType,
		$status,
		$sortOptions,
		$pageOptions,
		ConditionBlock $baseCondition = null
	) {

		$whereOptions = null;

		if ( ! is_null( $contentType ) || ! is_null( $status ) ) {
			$whereOptions = ConditionBlock::getConditionBlock( ConditionBuilder::CONDITION_BLOCK_LEVEL_OPERATOR_AND );
			if ( $baseCondition instanceof ConditionBlock ) {
				$whereOptions->addConditionBlock( $baseCondition );
			}

			if ( ! is_null( $contentType ) ) {
				$condition = Condition::getCondition( ConditionBuilder::CONDITION_SIGN_EQ, 'contentType',
					array ( $contentType ) );
				$whereOptions->addCondition( $condition );
			}

			if ( ! is_null( $status ) ) {
				$condition = Condition::getCondition( ConditionBuilder::CONDITION_SIGN_EQ, 'status',
					array ( $status ) );
				$whereOptions->addCondition( $condition );
			}
		}

		$query = QueryBuilder::buildSelectQuery(
			$this->dbal->completeTableName( self::SUBMISSIONS_TABLE_NAME ),
			array_keys( SubmissionEntity::$fieldsDefinition ),
			$whereOptions,
			$sortOptions,
			$pageOptions
		);

		$this->logger->info( $query );

		return $query;
	}

	/**
	 * @return array
	 */
	public function getColumnsLabels () {
		return SubmissionEntity::getFieldLabels();
	}

	/**
	 * @return array
	 */
	public function getSortableFields () {
		return SubmissionEntity::$fieldsSortable;
	}

	/**
	 * Stores SubmissionEntity to database. (fills id in needed)
	 *
	 * @param SubmissionEntity $entity
	 *
	 * @return SubmissionEntity
	 */
	public function storeEntity ( SubmissionEntity $entity ) {
		$entityId = $entity->id;

		$is_insert = in_array( $entityId, array ( 0, null ), true );

		$fields = $entity->toArray( false );
		unset ( $fields['id'] );

		if ( $is_insert ) {
			$storeQuery = QueryBuilder::buildInsertQuery( $this->dbal->completeTableName( self::SUBMISSIONS_TABLE_NAME ),
				$fields );
		} else {
			// update
			$conditionBlock = ConditionBlock::getConditionBlock();
			$conditionBlock->addCondition( Condition::getCondition( ConditionBuilder::CONDITION_SIGN_EQ, 'id',
				array ( $entityId ) ) );
			$storeQuery = QueryBuilder::buildUpdateQuery( $this->dbal->completeTableName( self::SUBMISSIONS_TABLE_NAME ),
				$fields, $conditionBlock, array ( 'limit' => 1 ) );
		}

		// log store query before execution
		$this->logger->info( $storeQuery );

		$result = $this->dbal->query( $storeQuery );

		if ( true === $is_insert && false !== $result ) {
			$entityFields       = $entity->toArray( false );
			$entityFields['id'] = $this->dbal->getLastInsertedId();
			// update reference to entity
			$entity = SubmissionEntity::fromArray( $entityFields, $this->logger );
		}

		return $entity;
	}

	/**
	 * @param array $fields
	 *
	 * @return SubmissionEntity
	 */
	public function createSubmission ( array $fields ) {
		return SubmissionEntity::fromArray( $fields, $this->logger );
	}
}