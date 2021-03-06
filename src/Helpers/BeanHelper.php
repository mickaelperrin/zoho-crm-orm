<?php

namespace Wabel\Zoho\CRM\Helpers;



use Wabel\Zoho\CRM\AbstractZohoDao;
use Wabel\Zoho\CRM\Exceptions\ZohoCRMORMException;
use Wabel\Zoho\CRM\Service\EntitiesGeneratorService;
use Wabel\Zoho\CRM\ZohoBeanInterface;
use zcrmsdk\crm\crud\ZCRMRecord;
use zcrmsdk\crm\setup\users\ZCRMUser;

class BeanHelper
{

    /**
     * @param AbstractZohoDao   $dao
     * @param ZohoBeanInterface $bean
     */
    public static function createOrUpdateBeanToZCRMRecord(AbstractZohoDao $dao, ZohoBeanInterface $bean)
    {

        $record = ZCRMRecord::getInstance($dao->getModule(), $bean->getZohoId());
        $bean->setZCRMRecord($record);
        foreach ($dao->getFields() as $field){
            if(($field->isSystem() && $field->getApiName() !== 'Owner') || in_array($field->getName(), EntitiesGeneratorService::$defaultORMSystemFields) || !$bean->isDirty($field->getName())) {
                continue;
            }
            $getter = $field->getGetter();
            switch ($field->getType()) {
            case 'date':
                /**
                 * @var $date \DateTimeInterface
                 */
                $date = $bean->{$getter}();
                if($date) {
                    $bean->getZCRMRecord()->setFieldValue($field->getApiName(), $date->format('Y-m-d'));
                }
                break;
            case 'datetime':
                /**
                 * @var $date \DateTimeInterface
                 */
                $date = $bean->{$getter}();
                if($date) {
                    $date->setTimezone(new \DateTimeZone($dao->getZohoClient()->getTimezone()));
                    $bean->getZCRMRecord()->setFieldValue($field->getApiName(), $date->format(\DateTime::ATOM));
                }
                break;
            case 'lookup':
                /**
                 * @var $ZCRMRecord ZCRMRecord
                 */
                $ZCRMRecord = ZCRMRecord::getInstance($field->getLookupModuleName(), $bean->{$getter}());
                $bean->getZCRMRecord()->setFieldValue($field->getApiName(), $ZCRMRecord);
                break;
            case 'ownerlookup':
                if($bean->{$getter}()) {
                    $bean->getZCRMRecord()->setOwner(ZCRMUser::getInstance($bean->{$getter}(), $bean->getOwnerOwnerName()));
                }
                break;
            case 'multiselectpicklist':
                if($bean->{$getter}()) {
                    $bean->getZCRMRecord()->setFieldValue($field->getApiName(), $bean->{$getter}());
                } else{
                    $bean->getZCRMRecord()->setFieldValue($field->getApiName(), null);
                }
                break;
            default:
                $bean->getZCRMRecord()->setFieldValue($field->getApiName(), $bean->{$getter}());
                break;
            }
        }

        // Add support for fields that can't be managed with ORM
        foreach ($dao->getUnamanagedFields() as $field => $value) {
            $bean->getZCRMRecord()->setFieldValue($field, $value);
        }
    }

    /**
     * @param  AbstractZohoDao   $dao
     * @param  ZohoBeanInterface $bean
     * @param  ZCRMRecord       $record
     * @throws ZohoCRMORMException
     */
    public static function updateZCRMRecordToBean(AbstractZohoDao $dao, ZohoBeanInterface $bean, ZCRMRecord $record)
    {
        $bean->setZCRMRecord($record);
        $id = $record->getEntityId();
        $bean->setZohoId($id);
        $bean->setCreatedTime(!empty($record->getCreatedTime()) ? \DateTimeImmutable::createFromFormat(\DateTime::ATOM, $record->getCreatedTime()) : null);
        $bean->setModifiedTime(!empty($record->getModifiedTime()) ? \DateTimeImmutable::createFromFormat(\DateTime::ATOM, $record->getModifiedTime()) : null);
        $bean->setLastActivityTime(!empty($record->getLastActivityTime()) ? \DateTimeImmutable::createFromFormat(\DateTime::ATOM, $record->getLastActivityTime()) : null);

        if($record->getOwner()) { $bean->setOwnerOwnerID($record->getOwner()->getId());
        }
        if($record->getOwner()) { $bean->setOwnerOwnerName($record->getOwner()->getName());
        }
        if($record->getModifiedBy()) { $bean->setModifiedByOwnerID($record->getModifiedBy()->getId());
        }
        if($record->getModifiedBy()) { $bean->setModifiedByOwnerName($record->getModifiedBy()->getName());
        }
        if($record->getCreatedBy()) { $bean->setCreatedByOwnerID($record->getCreatedBy()->getId());
        }
        if($record->getCreatedBy()) { $bean->setCreatedByOwnerName($record->getCreatedBy()->getName());
        }

        $fields = $dao->getFields();
        foreach ($fields as $field) {
            if(!$field->isSystem() && array_key_exists($field->getApiName(), $record->getData())) {
                $value = $record->getFieldValue($field->getApiName());
                $setter = $field->getSetter();
                switch ($field->getType()) {
                case 'date':
                    if ($value && $dateObj = \DateTime::createFromFormat('M/d/Y', $value)) {
                        $value = $dateObj;
                    } elseif ($value && $dateObj = \DateTime::createFromFormat('Y-m-d', $value)) {
                        $value = $dateObj;
                    } elseif ($value && $dateObj = \DateTime::createFromFormat(\DateTime::ATOM, $value)) {
                        $value = $dateObj;
                    } elseif($value !== null) {
                        throw new ZohoCRMORMException('Unable to convert the Date field "' . $field->getName() . "\" into a DateTime PHP object from the the record $id of the module " . $dao->getModule() . '.');
                    }
                    break;
                case 'datetime':
                    $value = \DateTime::createFromFormat(\DateTime::ATOM, $value);
                    break;
                case 'userlookup':
                case 'lookup':
                    /**
                     * @var $ZCRMRecord ZCRMRecord
                     */
                    $ZCRMRecord = $value;
                    $value = $ZCRMRecord? (is_a($ZCRMRecord, 'ZCRMRecord') ? $ZCRMRecord->getEntityId() : $ZCRMRecord):null;
                    break;

                case 'ownerlookup':
                    /**
                     * @var $ZCRMUser \ZCRMUser
                     */
                    $ZCRMUser = $value;
                    $value = $ZCRMUser?$ZCRMUser->getId():null;
                    break;
                default:
                    break;
                }
                if (($value === false || $value === null) && in_array($field->getType(), ['date', 'datetime', ''])) {
                    $value = null;
                }
                $bean->$setter($value);
                $bean->setDirty($field->getName(), false);
            }
        }

    }
}