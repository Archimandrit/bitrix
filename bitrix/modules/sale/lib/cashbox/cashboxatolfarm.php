<?php

namespace Bitrix\Sale\Cashbox;

use Bitrix\Main;
use Bitrix\Main\Localization;
use Bitrix\Sale\Cashbox\Internals\CashboxTable;
use Bitrix\Sale\Result;
use Bitrix\Catalog;

Localization\Loc::loadMessages(__FILE__);

/**
 * Class CashboxAtolFarm
 * @package Bitrix\Sale\Cashbox
 */
class CashboxAtolFarm extends Cashbox implements IPrintImmediately, ICheckable
{
	const OPERATION_CHECK_REGISTRY = 'registry';
	const OPERATION_CHECK_CHECK = 'check';
	const REQUEST_TYPE_GET = 'get';
	const REQUEST_TYPE_POST = 'post';
	const TOKEN_OPTION_NAME = 'atol_access_token';
	const SERVICE_URL = 'https://online.atol.ru/possystem/v3';
	const RESPONSE_HTTP_CODE_401 = 401;
	const RESPONSE_HTTP_CODE_200 = 200;

	/**
	 * @param Check $check
	 * @return array
	 */
	public function buildCheckQuery(Check $check)
	{
		$data = $check->getDataForCheck();

		/** @var Main\Type\DateTime $dateTime */
		$dateTime = $data['date_create'];

		$phone = \NormalizePhone($data['client_phone']);
		if (is_string($phone))
		{
			if ($phone[0] === '7')
				$phone = substr($phone, 1);
		}
		else
		{
			$phone = '';
		}

		$result = array(
			'timestamp' => $dateTime->format('d.m.Y H:i:s'),
			'external_id' => static::buildUuid(static::UUID_TYPE_CHECK, $data['unique_id']),
			'service' => array(
				'inn' => $this->getValueFromSettings('SERVICE', 'INN'),
				'callback_url' => $this->getCallbackUrl(),
				'payment_address' => $this->getValueFromSettings('SERVICE', 'P_ADDRESS'),
			),
			'receipt' => array(
				'attributes' => array(
					'email' => $data['client_email'] ?: '',
					'phone' => $phone,
					'sno' => $this->getValueFromSettings('TAX', 'SNO'),
				),
				'payments' => array(),
				'items' => array(),
				'total' => (float)$data['total_sum']
			)
		);

		foreach ($data['payments'] as $payment)
		{
			$result['receipt']['payments'][] = array(
				'type' => (int)$this->getValueFromSettings('PAYMENT_TYPE', $payment['is_cash']),
				'sum' => (float)$payment['sum']
			);
		}

		foreach ($data['items'] as $i => $item)
		{
			$vat = $this->getValueFromSettings('VAT', $item['vat']);

			$result['receipt']['items'][] = array(
				'name' => $item['name'],
				'price' => (float)$item['price'],
				'sum' => (float)$item['sum'],
				'quantity' => $item['quantity'],
				'tax' => ($vat !== null) ? $vat : $this->getValueFromSettings('VAT', 'NOT_VAT')
			);
		}

		return $result;
	}

	/**
	 * @return string
	 */
	private function getCallbackUrl()
	{
		$context = Main\Application::getInstance()->getContext();
		$scheme = $context->getRequest()->isHttps() ? 'https' : 'http';
		$server = $context->getServer();
		$domain = $server->getServerName();

		if (preg_match('/^(?<domain>.+):(?<port>\d+)$/', $domain, $matches))
		{
			$domain = $matches['domain'];
			$port   = $matches['port'];
		}
		else
		{
			$port = $server->getServerPort();
		}
		$port = in_array($port, array(80, 443)) ? '' : ':'.$port;

		return sprintf('%s://%s%s/bitrix/tools/sale_farm_check_print.php', $scheme, $domain, $port);
	}

	/**
	 * @return string
	 */
	public static function getName()
	{
		return Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_TITLE');
	}

	/**
	 * @param array $data
	 * @return array
	 */
	protected static function extractCheckData(array $data)
	{
		$result = array();

		if (!$data['uuid'])
			return $result;

		$checkInfo = CheckManager::getCheckInfoByExternalUuid($data['uuid']);

		if ($data['error'])
		{
			$errorType = static::getErrorType($data['error']['code']);

			$result['ERROR'] = array(
				'CODE' => $data['error']['code'],
				'MESSAGE' => $data['error']['text'],
				'TYPE' => ($errorType === Errors\Error::TYPE) ? Errors\Error::TYPE : Errors\Warning::TYPE
			);
		}

		$result['ID'] = $checkInfo['ID'];
		$result['CHECK_TYPE'] = $checkInfo['TYPE'];

		$check = CheckManager::getObjectById($checkInfo['ID']);
		$dateTime = new Main\Type\DateTime($data['payload']['receipt_datetime']);
		$result['LINK_PARAMS'] = array(
			Check::PARAM_REG_NUMBER_KKT => $data['payload']['ecr_registration_number'],
			Check::PARAM_FISCAL_DOC_ATTR => $data['payload']['fiscal_document_attribute'],
			Check::PARAM_FISCAL_DOC_NUMBER => $data['payload']['fiscal_document_number'],
			Check::PARAM_FISCAL_RECEIPT_NUMBER => $data['payload']['fiscal_receipt_number'],
			Check::PARAM_FN_NUMBER => $data['payload']['fn_number'],
			Check::PARAM_SHIFT_NUMBER => $data['payload']['shift_number'],
			Check::PARAM_DOC_SUM => $data['payload']['total'],
			Check::PARAM_DOC_TIME => $dateTime->getTimestamp(),
			Check::PARAM_CALCULATION_ATTR => $check::getCalculatedSign()
		);

		return $result;
	}

	/**
	 * @param $id
	 * @return array
	 */
	public function buildZReportQuery($id)
	{
		return array();
	}

	/**
	 * @param array $data
	 * @return array
	 */
	protected static function extractZReportData(array $data)
	{
		return array();
	}

	/**
	 * @return array
	 */
	private function getCheckTypeMap()
	{
		return array(
			SellCheck::getType() => 'sell',
			SellReturnCashCheck::getType() => 'sell_refund',
			SellReturnCheck::getType() => 'sell_refund'
		);
	}

	/**
	 * @param $operation
	 * @param $token
	 * @param array $queryData
	 * @return string
	 * @throws Main\SystemException
	 */
	private function getUrl($operation, $token, array $queryData = array())
	{
		$groupCode = $this->getField('NUMBER_KKM');

		if ($operation === static::OPERATION_CHECK_REGISTRY)
		{
			return static::SERVICE_URL.'/'.$groupCode.'/'.$queryData['CHECK_TYPE'].'?tokenid='.$token;
		}
		elseif ($operation === static::OPERATION_CHECK_CHECK)
		{
			return static::SERVICE_URL.'/'.$groupCode.'/report/'.$queryData['EXTERNAL_UUID'].'?tokenid='.$token;
		}

		throw new Main\SystemException();
	}

	/**
	 * @param Check $check
	 * @return Result
	 */
	public function printImmediately(Check $check)
	{
		$printResult = new Result();

		$token = $this->getAccessToken();
		if ($token === '')
		{
			$token = $this->requestAccessToken();
			if ($token === '')
			{
				$printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
				return $printResult;
			}
		}

		$checkQuery = static::buildCheckQuery($check);
		$validateResult = $this->validate($checkQuery);
		if (!$validateResult->isSuccess())
		{
			return $validateResult;
		}

		$checkTypeMap = $this->getCheckTypeMap();
		$checkType = $checkTypeMap[$check::getType()];

		$url = $this->getUrl(static::OPERATION_CHECK_REGISTRY, $token, array('CHECK_TYPE' => $checkType));

		$result = $this->send(static::REQUEST_TYPE_POST, $url, $checkQuery);
		if (!$result->isSuccess())
			return $result;

		$response = $result->getData();
		if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401)
		{
			$token = $this->requestAccessToken();
			if ($token === '')
			{
				$printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
				return $printResult;
			}

			$url = $this->getUrl(static::OPERATION_CHECK_REGISTRY, $token, array('CHECK_TYPE' => $checkType));
			$result = $this->send(static::REQUEST_TYPE_POST, $url, $checkQuery);
			if (!$result->isSuccess())
				return $result;

			$response = $result->getData();
		}

		if ($response['http_code'] === static::RESPONSE_HTTP_CODE_200)
		{
			if ($response['uuid'])
			{
				$printResult->setData(array('UUID' => $response['uuid']));
			}
			else
			{
				$printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_CHECK_REG_ERROR')));
			}
		}
		else
		{
			if (isset($response['error']['text']))
			{
				$printResult->addError(new Main\Error($response['error']['text']));
			}
			else
			{
				$printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_CHECK_REG_ERROR')));
			}
		}

		return $printResult;
	}

	/**
	 * @param Check $check
	 * @return Result
	 */
	public function check(Check $check)
	{
		$url = $this->getUrl(
			static::OPERATION_CHECK_CHECK,
			$this->getAccessToken(),
			array('EXTERNAL_UUID' => $check->getField('EXTERNAL_UUID'))
		);

		$result = $this->send(static::REQUEST_TYPE_GET, $url);
		if (!$result->isSuccess())
			return $result;

		$response = $result->getData();
		if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401)
		{
			$token = $this->requestAccessToken();
			if ($token === '')
			{
				$result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_REQUEST_TOKEN_ERROR')));
				return $result;
			}

			$url = $this->getUrl(
				static::OPERATION_CHECK_CHECK,
				$this->getAccessToken(),
				array('EXTERNAL_UUID' => $check->getField('EXTERNAL_UUID'))
			);

			$result = $this->send(static::REQUEST_TYPE_GET, $url);
			if (!$result->isSuccess())
				return $result;

			$response = $result->getData();
		}

		return static::applyCheckResult($response);
	}

	/**
	 * @param array $checkData
	 * @return Result
	 */
	private function validate(array $checkData)
	{
		$result = new Result();

		if ($checkData['receipt']['attributes']['email'] === '' && $checkData['receipt']['attributes']['phone'] === '')
		{
			$result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_ERR_EMPTY_PHONE_EMAIL')));
		}

		foreach ($checkData['receipt']['items'] as $item)
		{
			if ($item['tax'] === null)
			{
				$result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ATOL_ERR_EMPTY_TAX')));
				break;
			}
		}

		return $result;
	}

	/**
	 * @param $method
	 * @param $url
	 * @param array $data
	 * @return Result
	 */
	private function send($method, $url, array $data = array())
	{
		$result = new Result();

		$http = new Main\Web\HttpClient();

		if ($method === static::REQUEST_TYPE_POST)
		{
			$http->disableSslVerification();
			$data = $this->encode($data);
			$response = $http->post($url, $data);
		}
		else
		{
			$response = $http->get($url);
		}

		if ($response !== false)
		{
			try
			{
				$response = $this->decode($response);
				if (!is_array($response))
					$response = array();

				$response['http_code'] = $http->getStatus();
				$result->addData($response);
			}
			catch (Main\ArgumentException $e)
			{
				$result->addError(new Main\Error($e->getMessage()));
			}
		}
		else
		{
			$error = $http->getError();
			foreach ($error as $code =>$message)
			{
				$result->addError(new Main\Error($message, $code));
			}
		}

		return $result;
	}

	/**
	 * @param int $modelId
	 * @return array
	 */
	public static function getSettings($modelId = 0)
	{
		$settings = array(
			'AUTH' => array(
				'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_AUTH'),
				'REQUIRED' => 'Y',
				'ITEMS' => array(
					'LOGIN' => array(
						'TYPE' => 'STRING',
						'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_AUTH_LOGIN_LABEL')
					),
					'PASS' => array(
						'TYPE' => 'STRING',
						'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_AUTH_PASS_LABEL')
					),
				)
			),
			'SERVICE' => array(
				'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE'),
				'REQUIRED' => 'Y',
				'ITEMS' => array(
					'INN' => array(
						'TYPE' => 'STRING',
						'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_INN_LABEL')
					),
					'P_ADDRESS' => array(
						'TYPE' => 'STRING',
						'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SERVICE_URL_LABEL')
					),
				)
			)
		);

		$settings['PAYMENT_TYPE'] = array(
			'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_P_TYPE'),
			'REQUIRED' => 'Y',
			'ITEMS' => array()
		);

		$systemPaymentType = array('Y' => 0, 'N' => 1, 'A' => 1);
		foreach ($systemPaymentType as $type => $value)
		{
			$settings['PAYMENT_TYPE']['ITEMS'][$type] = array(
				'TYPE' => 'STRING',
				'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_P_TYPE_LABEL_'.$type),
				'VALUE' => $value
			);
		}

		$settings['VAT'] = array(
			'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_BITRIX_SETTINGS_VAT'),
			'REQUIRED' => 'Y',
			'ITEMS' => array(
				'NOT_VAT' => array(
					'TYPE' => 'STRING',
					'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_BITRIX_SETTINGS_VAT_LABEL_NOT_VAT'),
					'VALUE' => 'none'
				)
			)
		);

		if (Main\Loader::includeModule('catalog'))
		{
			$dbRes = Catalog\VatTable::getList(array('filter' => array('ACTIVE' => 'Y')));
			$vatList = $dbRes->fetchAll();
			if ($vatList)
			{
				$defaultVat = array(0 => 'vat0', 10 => 'vat10', 18 => 'vat18');
				foreach ($vatList as $vat)
				{
					$value = '';
					if (isset($defaultVat[(int)$vat['RATE']]))
						$value = $defaultVat[(int)$vat['RATE']];

					$settings['VAT']['ITEMS'][(int)$vat['ID']] = array(
						'TYPE' => 'STRING',
						'LABEL' => $vat['NAME'].' ['.(int)$vat['RATE'].'%]',
						'VALUE' => $value
					);
				}
			}
		}

		$settings['TAX'] = array(
			'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SNO'),
			'REQUIRED' => 'Y',
			'ITEMS' => array(
				'SNO' => array(
					'TYPE' => 'ENUM',
					'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SETTINGS_SNO_LABEL'),
					'VALUE' => 'osn',
					'OPTIONS' => array(
						'osn' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_OSN'),
						'usn_income' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_UI'),
						'usn_income_outcome' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_UIO'),
						'envd' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_ENVD'),
						'esn' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_ESN'),
						'patent' => Localization\Loc::getMessage('SALE_CASHBOX_ATOL_FARM_SNO_PATENT')
					)
				)
			)
		);

		return $settings;
	}

	/**
	 * @return array
	 */
	public static function getGeneralRequiredFields()
	{
		$generalRequiredFields = parent::getGeneralRequiredFields();

		$map = CashboxTable::getMap();
		$generalRequiredFields['NUMBER_KKM'] = $map['NUMBER_KKM']['title'];
		return $generalRequiredFields;
	}

	/**
	 * @return string
	 */
	private function getAccessToken()
	{
		return Main\Config\Option::get('sale', $this->getOptionName(), '');
	}

	/**
	 * @param $token
	 */
	private function setAccessToken($token)
	{
		Main\Config\Option::set('sale', $this->getOptionName(), $token);
	}

	/**
	 * @return string
	 */
	private function getOptionName()
	{
		return static::TOKEN_OPTION_NAME.'_'.ToLower($this->getField('NUMBER_KKM'));
	}

	/**
	 * @param array $data
	 * @return mixed
	 */
	private function encode(array $data)
	{
		return Main\Web\Json::encode($data);
	}

	/**
	 * @param string $data
	 * @return mixed
	 */
	private function decode($data)
	{
		return Main\Web\Json::decode($data);
	}

	/**
	 * @return string
	 */
	private function requestAccessToken()
	{
		$url = static::SERVICE_URL.'/getToken';
		$data = array(
			'login' => $this->getValueFromSettings('AUTH', 'LOGIN'),
			'pass' => $this->getValueFromSettings('AUTH', 'PASS')
		);

		$result = $this->send(static::REQUEST_TYPE_POST, $url, $data);
		if ($result->isSuccess())
		{
			$response = $result->getData();
			$this->setAccessToken($response['token']);

			return $response['token'];
		}

		return '';
	}

	/**
	 * @param $errorCode
	 * @throws Main\NotImplementedException
	 * @return int
	 */
	protected static function getErrorType($errorCode)
	{
		return Errors\Error::TYPE;
	}

	/**
	 * @return bool
	 */
	public static function isSupportedFFD105()
	{
		return false;
	}
}
