<?php

namespace Bitrix\Sale\Exchange;


use Bitrix\Sale\Exchange\OneC\OrderDocument;
use Bitrix\Sale\Exchange\OneC\ShipmentDocument;

class ImportOneCPackageSale extends ImportOneCPackage
{
	protected function convert(array $documents)
	{
		$settingsShipment = Manager::getSettingsByType(EntityType::SHIPMENT);

		if($settingsShipment->canCreateOrder(EntityType::SHIPMENT)=='Y' && !$this->hasDocumentByTypeId(EntityType::ORDER, $documents))
		{
			$documentShipment = $this->getDocumentByTypeId(EntityType::SHIPMENT, $documents);
			if($documentShipment !== null)
			{
				$order['ID_1C'] = $documentShipment->getField('ID_1C');
				$order['VERSION_1C'] = $documentShipment->getField('VERSION_1C');
				$order['AMOUNT'] = $documentShipment->getField('AMOUNT');
				$order['ITEMS'] = $documentShipment->getField('ITEMS');
				$order['TAXES'] = $documentShipment->getField('TAXES');
				$order['AGENT'] = $documentShipment->getField('AGENT');

				$documentOrder = new OrderDocument();
				$documentOrder->setFields($order);
				$documents[] = $documentOrder;
			}
		}

		//region Presset - create Shipment if Service in the Order by information from 1C
		if(!isset($documentOrder))
			$documentOrder = $this->getDocumentByTypeId(EntityType::ORDER, $documents);

		$documentShipment = $this->getDocumentByTypeId(EntityType::SHIPMENT, $documents);
		if($documentShipment == null)
		{
			$fieldsOrder = $documentOrder->getFieldValues();
			if($this->deliveryServiceExists($fieldsOrder['ITEMS']))
			{
				$shipment['ID_1C'] = $documentOrder->getField('ID_1C');
				$shipment['VERSION_1C'] = $documentOrder->getField('VERSION_1C');
				$shipment['ITEMS'] = $documentOrder->getField('ITEMS');

				$documentShipment = new ShipmentDocument();
				$documentShipment->setFields($shipment);
				$documents[] = $documentShipment;
			}
		}
		//endregion

		foreach($documents as $document)
		{
			if($document instanceof OneC\PaymentDocument)
			{
				$paymentFields = $document->getFieldValues();
				$paymentFields['REK_VALUES']['PAY_SYSTEM_ID_DEFAULT'] = $this->getDefaultPaySystem($documentOrder);
				$document->setFields($paymentFields);
			}

			if($document instanceof OneC\ShipmentDocument)
			{
				$shimpentFields = $document->getFieldValues();
				$shimpentFields['REK_VALUES']['DELIVERY_SYSTEM_ID_DEFAULT'] = $this->getDefaultDeliverySystem($documentOrder);
				$document->setFields($shimpentFields);
			}
		}

		return parent::convert($documents);
	}
}