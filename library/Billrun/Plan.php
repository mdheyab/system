<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing plan class
 *
 * @package  Plan
 * @since    0.5
 */
class Billrun_Plan extends Billrun_Service {

	const PLAN_SPAN_YEAR = 'year';
	const PLAN_SPAN_MONTH = 'month';

	protected static $plans = array();
	protected $plan_ref = array();
	protected $planActivation;
	protected $planDeactivation = null;

	/**
	 * constructor
	 * set the data instance
	 * 
	 * @param array $params array of parameters (plan name & time)
	 */
	public function __construct(array $params = array()) {
		if ((!isset($params['name']) || !isset($params['time'])) && (!isset($params['id'])) && (!isset($params['data']))) {
			//throw an error
			throw new Exception("plan constructor was called  without the appropriate parameters , got : " . print_r($params, 1));
		}
		if (isset($params['data'])) {
			$this->data = $params['data'];
		} else if (isset($params['id'])) {
			$this->constructWithID($params['id']);
		} else {
			$this->constructWithActivePlan($params);
		}

		$this->constructExtraOptions($params);
	}

	/**
	 * Query the DB with the input ID and set it as the plan data.
	 * @param type $id
	 * @todo use load method
	 */
	protected function constructWithID($id) {
		if ($id instanceof Mongodloid_Id) {
			$filter_id = strval($id->getMongoId());
		} else if ($id instanceof MongoId) {
			$filter_id = strval($id);
		} else {
			// probably a string
			$filter_id = $id;
		}
		$plan = $this->getPlanById($filter_id);
		if ($plan) {
			$this->data = $plan;
		} else {
			$this->data = Billrun_Factory::db()->plansCollection()->findOne($id);
			$this->data->collection(Billrun_Factory::db()->plansCollection());
		}
	}

	/**
	 * Construct the plan with the active plan in the data base
	 * @param type $params
	 * @return type
	 * @todo use load method
	 */
	protected function constructWithActivePlan($params) {
		$date = new MongoDate($params['time']);
		$plan = self::getPlanByNameAndTime($params['name'], $date);
		if ($plan) {
			$this->data = $plan;
			return;
		}

		$planQuery = array(
			'name' => $params['name'],
			'$or' => array(
				array('to' => array('$gt' => $date)),
				array('to' => null)
			)
		);
		$plansColl = Billrun_Factory::db()->plansCollection();
		$planRecord = $plansColl->query($planQuery)->lessEq('from', $date)->cursor()->current();
		$planRecord->collection($plansColl);
		$this->data = $planRecord;
	}

	/**
	 * Handle constructing the instance with extra input options, if available
	 * @param arrray $options
	 */
	protected function constructExtraOptions($options) {
		if (isset($options['activation'])) {
			$this->planActivation = $options['activation'];
		}
		if (isset($options['deactivation'])) {
			$this->planDeactivation = $options['deactivation'];
		}
	}

	public function getData($raw = false) {
		if ($raw) {
			return $this->data->getRawData();
		}
		return $this->data;
	}

	public function getActivation() {
		return $this->planActivation;
	}

	public function getDectivation() {
		return $this->planDeactivation;
	}

	protected static function initPlans() {
		$plans_coll = Billrun_Factory::db()->plansCollection();
		$plans = $plans_coll->query()->cursor();
		foreach ($plans as $plan) {
			$plan->collection($plans_coll);
			self::$plans['by_id'][strval($plan->getId())] = $plan;
			self::$plans['by_name'][$plan['name']][] = array(
				'plan' => $plan,
				'from' => $plan['from'],
				'to' => $plan['to'],
			);
		}
	}

	public static function getPlans() {
		if (empty(self::$plans)) {
			self::initPlans();
		}
		return self::$plans;
	}

	/**
	 * get the plan by its id
	 * 
	 * @param string $id
	 * 
	 * @return array of plan details if id exists else false
	 */
	protected function getPlanById($id) {
		if (isset(self::$plans['by_id'][$id])) {
			return self::$plans['by_id'][$id];
		}
		return false;
	}

	/**
	 * get plan by name and date
	 * plan is time-depend
	 * @param string $name name of the plan
	 * @param int $time unix timestamp
	 * @return array with plan details if plan exists, else false
	 */
	protected static function getPlanByNameAndTime($name, $time) {
		$plans = static::getPlans();
		if (isset($plans['by_name'][$name])) {
			foreach ($plans['by_name'][$name] as $planTimes) {
				if ($planTimes['from'] <= $time && (!isset($planTimes['to']) || is_null($planTimes['to']) || $planTimes['to'] >= $time)) {
					return $planTimes['plan'];
				}
			}
		}
		return false;
	}

	/**
	 * check if a usage type included as part of the plan
	 * @param type $rate
	 * @param type $type
	 * @return boolean
	 * @deprecated since version 0.1
	 * 		should be removed from here;
	 * 		the check of plan should be run on line not subscriber/balance
	 */
	public function isRateInBasePlan($rate, $type) {
		return isset($rate['rates'][$type]['plans']) &&
			is_array($rate['rates'][$type]['plans']) &&
			in_array($this->createRef(), $rate['rates'][$type]['plans']);
	}

	/**
	 * method to check if a usage type included in the rate plan
	 * rate plan means that there is rate that have balance that included as part of the plan
	 * it's described in the plan meta data
	 * 
	 * @param array $rate details of the rate
	 * @param string $usageType the usage type
	 * 
	 * @return boolean
	 * @since 2.6
	 * @deprecated since version 2.7
	 */
	public function isRateInPlanRate($rate, $usageType) {
		return (isset($this->data['include']['rates'][$rate['key']][$usageType]));
	}

	/**
	 * check if usage left in the rate balance (part of the plan)
	 * 
	 * @param array $subscriberBalance subscriber balance to check
	 * @param array $rate the rate to check
	 * @param string $usageType usage type to check
	 * @return int the usage left
	 * @since 2.6
	 * @deprecated since version 2.7
	 */
	public function usageLeftInRateBalance($subscriberBalance, $rate, $usageType = 'call') {
		if (!isset($this->get('include')[$rate['key']][$usageType])) {
			return 0;
		}

		$rateUsageIncluded = $this->get('include')[$rate['key']][$usageType];

		if ($rateUsageIncluded === 'UNLIMITED') {
			return PHP_INT_MAX;
		}

		if (isset($subscriberBalance['rates'][$rate['key']][$usageType]['usagev'])) {
			$subscriberSpent = $subscriberBalance['rates'][$rate['key']][$usageType]['usagev'];
		} else {
			$subscriberSpent = 0;
		}
		$usageLeft = $rateUsageIncluded - $subscriberSpent;
		return floatval($usageLeft < 0 ? 0 : $usageLeft);
	}

	/**
	 * Get the usage left in the current plan.
	 * @param $subscriberBalance the current sunscriber balance.
	 * @param $usagetype the usage type to check.
	 * @return int  the usage  left in the usage type of the subscriber.
	 * @deprecated since version 5.0
	 */
	public function usageLeftInBasePlan($subscriberBalance, $rate, $usagetype = 'call') {

		if (!isset($this->get('include')[$usagetype])) {
			return 0;
		}

		$usageIncluded = $this->get('include')[$usagetype];
		if ($usageIncluded == 'UNLIMITED') {
			return PHP_INT_MAX;
		}

		$usageLeft = $usageIncluded - $subscriberBalance['balance']['totals'][$this->getBalanceTotalsKey($usagetype, $rate)]['usagev'];
		return floatval($usageLeft < 0 ? 0 : $usageLeft);
	}

	/**
	 * Get the price of the current plan.
	 * @return float the price of the plan without VAT.
	 */
	public function getPrice($from, $to, $firstActivation = null) {
		if ($firstActivation === null) {
			$firstActivation = $this->getActivation();
		}

		$startOffset = static::getMonthsDiff($firstActivation, date(Billrun_Base::base_dateformat, strtotime('-1 day', strtotime($from))));
		$endOffset = static::getMonthsDiff($firstActivation, $to);
		$charge = 0;
		if ($this->isUpfrontPayment()) {
			return $this->getPriceForUpfrontPayment($startOffset);
		}

		if ($this->getPeriodicity() != 'month') {
			Billrun_Factory::log("Plan get price cannot handle non month periodicity value");
			return 0;
		}

		foreach ($this->data['price'] as $tariff) {
			$charge += self::getPriceByTariff($tariff, $startOffset, $endOffset);
		}
		return $charge;
	}

	/**
	 * Validate the input to the getPriceByTariff function
	 * @param type $tariff
	 * @param type $startOffset
	 * @param type $endOffset
	 * @return boolean
	 */
	protected static function validatePriceByTariff($tariff, $startOffset, $endOffset) {
		if ($tariff['from'] > $tariff['to']) {
			Billrun_Factory::log("getPriceByTariff received invalid tariff.");
			return false;
		}

		if ($startOffset > $endOffset) {
			Billrun_Factory::log("getPriceByTariff received invalid offset values.");
			return false;
		}

		if ($startOffset > $tariff['to']) {
			Billrun_Factory::log("getPriceByTariff start offset is out of bounds.");
			return false;
		}

		if ($endOffset < $tariff['from']) {
			Billrun_Factory::log("getPriceByTariff end offset is out of bounds.");
			return false;
		}
		return true;
	}

	/**
	 * Get the price to charge by a tariff
	 * @param type $tariff
	 * @param type $startOffset
	 * @param type $endOffset
	 * @return int
	 */
	public static function getPriceByTariff($tariff, $startOffset, $endOffset) {
		if (!self::validatePriceByTariff($tariff, $startOffset, $endOffset)) {
			return 0;
		}

		$endPricing = $endOffset;
		$startPricing = $startOffset;

		if ($tariff['from'] > $startOffset) {
			$startPricing = $tariff['from'];
		}
		if ($tariff['to'] < $endOffset) {
			$endPricing = $tariff['to'];
		}

		return ($endPricing - $startPricing) * $tariff['price'];
	}

	/**
	 * Get the price of the current plan when the plan is to be paid upfront
	 * @param type $startOffset
	 * @return price
	 */
	protected function getPriceForUpfrontPayment($startOffset) {
		if ($this->getPeriodicity() == 'year') {
			$startOffset = $startOffset / 12;
		}
		foreach ($this->data['price'] as $tariff) {
			if ($tariff['from'] <= $startOffset && $tariff['to'] > $startOffset) {
				return $tariff['price'];
			}
		}

		return 0;
	}

	public function getSpan() {
		return $this->data['recurrence']['unit'];
	}

	public function getPeriodicity() {
		return $this->data['recurrence']['periodicity'];
	}

	/**
	 * create  a DB reference to the current plan
	 * @param type $collection (optional) the collection to use to create the reference.
	 * @return MongoDBRef the refernce to current plan.
	 * @todo Should the collection here really be false by default? I think it's safer 
	 * if the user of this function will have to specify a collection.
	 */
	public function createRef($collection = false) {
		if (count($this->plan_ref) == 0) {
			$collection = $collection ? $collection :
				($this->data->collection() ? $this->data->collection() : Billrun_Factory::db()->plansCollection() );
			$this->plan_ref = $collection->createRefByEntity($this->data);
		}
		return $this->plan_ref;
	}

	public function isUnlimited($usage_type) {
		return isset($this->data['include'][$usage_type]) && $this->data['include'][$usage_type] == "UNLIMITED";
	}

	public function isUnlimitedRate($rate, $usageType) {
		return (isset($this->data['include']['rates'][$rate['key']][$usageType]) && $this->data['include']['rates'][$rate['key']][$usageType] == "UNLIMITED");
	}

	public function isUnlimitedGroup($rate, $usageType) {
		$groupSelected = $this->getEntityGroup();
		if ($groupSelected === FALSE) {
			return FALSE;
		}
		return (isset($this->data['include']['groups'][$groupSelected][$usageType]) && $this->data['include']['groups'][$groupSelected][$usageType] == "UNLIMITED");
	}

	/**
	 * method to get balance totals key
	 * 
	 * @param string $usage_type
	 * @param array $rate rate handle
	 * @return string
	 * 
	 * @deprecated since version 5.2
	 */
	public function getBalanceTotalsKey($usage_type, $rate) {
		if ($this->isRateInBasePlan($rate, $usage_type)) {
			$usage_class_prefix = "";
		} else {
			$usage_class_prefix = "out_plan_";
		}
		return $usage_class_prefix . $usage_type;
	}

	public function isUpfrontPayment() {
		return !empty($this->data['upfront']);
	}

	/**
	 * Function calculates inclusive diff. i.e. identical dates return diff > 0
	 * @param type $from
	 * @param type $to
	 * @return type
	 */
	public static function getMonthsDiff($from, $to) {
		$minDate = new DateTime($from);
		$maxDate = new DateTime($to);
		if ($minDate->format('d') - 1 == $maxDate->format('d')) {
			return $maxDate->diff($minDate)->m + round($maxDate->diff($minDate)->d / 30);
		}
		if ($minDate->format('d') == 1 && (new DateTime($from))->modify('-1 day')->format('t') == $maxDate->format('d')) {
			return $maxDate->diff((new DateTime($from))->modify('-1 day'))->m;
		}
		if ($minDate->format('Y') == $maxDate->format('Y') && $minDate->format('m') == $maxDate->format('m')) {
			return ($maxDate->format('d') - $minDate->format('d') + 1) / $minDate->format('t');
		}
		$yearDiff = $maxDate->format('Y') - $minDate->format('Y');
		switch ($yearDiff) {
			case 0:
				$months = $maxDate->format('m') - $minDate->format('m') - 1;
				break;
			default :
				$months = $maxDate->format('m') + 11 - $minDate->format('m') + ($yearDiff - 1) * 12;
				break;
		}
		return ($minDate->format('t') - $minDate->format('d') + 1) / $minDate->format('t') + $maxDate->format('d') / $maxDate->format('t') + $months;
	}

	public static function calcFractionOfMonth($billrunKey, $start_date, $end_date) {
		$billing_start_date = Billrun_Billingcycle::getStartTime($billrunKey);
		$billing_end_date = Billrun_Billingcycle::getEndTime($billrunKey);
		$days_in_month = (int) date('t', $billing_start_date);
		$temp_start = strtotime($start_date);
		$temp_end = is_null($end_date) ? PHP_INT_MAX : strtotime($end_date);
		$start = $billing_start_date > $temp_start ? $billing_start_date : $temp_start;
		$end = $billing_end_date < $temp_end ? $billing_end_date : $temp_end;
		if ($end < $start) {
			return 0;
		}
		$start_day = date('j', $start);
		$end_day = date('j', $end);
		$start_month = date('F', $start);
		$end_month = date('F', $end);

		if ($start_month == $end_month) {
			$days_in_plan = (int) $end_day - (int) $start_day + 1;
		} else {
			$days_in_previous_month = $days_in_month - (int) $start_day + 1;
			$days_in_current_month = (int) $end_day;
			$days_in_plan = $days_in_previous_month + $days_in_current_month;
		}

		$fraction = $days_in_plan / $days_in_month;
		return $fraction;
	}
	
	public function getFieldsForLine() {
		return Billrun_Factory::config()->getConfigValue('plans.lineFields', array());
	}

}
