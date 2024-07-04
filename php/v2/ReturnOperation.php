<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;


/**
 * Похоже на канал уведомлений для тех-поддержки
 * Сотрудников уведомлеяет о создании товых тикетов
 * Клиенты получают уведомления об изменении статуса
 *
 * По поводу качества: не рабочее - в основном из-за неверно указанного типа возвращаемых данных у метода doOperation
 * В остальном вижу 3 основные ошибки:
 *  1. Плохая валидация входящих данных, код не устойчив к невалидным данным
 *  2. Отсутствие типизации внутри логики - многое держится на вложенных массивах
 *  3. Вся логика в одном методе
 *
 *
 * Провёл рефаторинг метода doOperation, сохранив основную логику
 *
 * Добавл типизацию, немного проверок и оптимизации
 */
class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $inputData = $this->InputData($data);
        if (is_array($inputData))
            return $inputData;

        $result = new ResultOperation();
        $templateData = $this->TemplateData($data, $inputData);

        $this->SendToEmployees($templateData, $inputData, $result);
        $this->SendToClients( $templateData, $inputData, $result);


        return $result->ToArray();
    }

    /**
     * @throws Exception
     */
    protected function InputData(array $data): InputData|array
    {
        $resellerId = (int)$data['resellerId'];
        $notificationType = (int)$data['notificationType'];

        if (empty($resellerId)) {
            $result = new ResultOperation();
            $result->notificationClientBySms->message = 'Empty resellerId';
            return $result->ToArray();
        }

        if (empty($notificationType)) {
            throw new Exception('Empty notificationType', 400);
        }

        $reseller = Seller::getById((int)$resellerId);
        if (is_null($reseller)) {
            throw new Exception('Seller not found!', 400);
        }

        $client = Contractor::getById((int)$data['clientId']);
        if (is_null($client) || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new Exception('сlient not found!', 400);
        }

        $creator = Employee::getById((int)$data['creatorId']);
        if (is_null($creator)) {
            throw new Exception('Creator not found!', 400);
        }

        $expert = Employee::getById((int)$data['expertId']);
        if (is_null($expert)) {
            throw new Exception('Expert not found!', 400);
        }
        $inputData = new InputData(
            $resellerId,
            $notificationType,
            $reseller,
            $client,
            $creator,
            $expert);

        if (array_key_exists('differences', $data) && array_key_exists('from', $data['differences']))
            $inputData->differences_from = (int)$data['differences']['from'];

        if (array_key_exists('differences', $data) && array_key_exists('to', $data['differences']))
            $inputData->differences_to = (int)$data['differences']['to'];

        return $inputData;
    }

    /**
     * @throws Exception
     */
    protected function TemplateData(array $data, InputData $inputData): array
    {
        $cFullName = $inputData->client->getFullName();
        if (empty($inputData->client->getFullName())) {
            $cFullName = $inputData->client->name;
        }

        $differences = '';
        if ($inputData->notificationType === TsReturnOperation::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $inputData->resellerId);
        } elseif ($inputData->notificationType === TsReturnOperation::TYPE_CHANGE &&
            !is_null($inputData->differences_from) &&
            !is_null($inputData->differences_to)) {

            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$inputData->differences_from),
                'TO' => Status::getName((int)$inputData->differences_to),
            ], $inputData->resellerId);
        }

        $templateData = [
            'COMPLAINT_ID' => (int)$data['complaintId'],
            'COMPLAINT_NUMBER' => (string)$data['complaintNumber'],
            'CREATOR_ID' => (int)$data['creatorId'],
            'CREATOR_NAME' => $inputData->creator->getFullName(),
            'EXPERT_ID' => (int)$data['expertId'],
            'EXPERT_NAME' => $inputData->expert->getFullName(),
            'CLIENT_ID' => (int)$data['clientId'],
            'CLIENT_NAME' => $cFullName,
            'CONSUMPTION_ID' => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string)$data['agreementNumber'],
            'DATE' => (string)$data['date'],
            'DIFFERENCES' => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new Exception("Template Data ({$key}) is empty!", 500);
            }
        }
        return $templateData;
    }

    protected function SendToEmployees(array $templateData, InputData $inputData, ResultOperation $result): void
    {

        $emailFrom = getResellerEmailFrom($inputData->resellerId);
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($inputData->resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            $subject = __('complaintEmployeeEmailSubject', $templateData, $inputData->resellerId);
            $message = __('complaintEmployeeEmailBody', $templateData, $inputData->resellerId);
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => $subject,
                        'message' => $message,
                    ], // [G] возможно метод принимается массив сообщений, тогда его можно будет вынести из цикла, и отдавать ему сразу пачку всех сообщений
                    // [G] но для обратной совместимости, выносим только запрос шаблонов
                ], $inputData->resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result->notificationEmployeeByEmail = true;

            }
        }
    }

    protected function SendToClients(array $templateData, InputData $inputData, ResultOperation $result): void
    {
        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($inputData->notificationType !== self::TYPE_CHANGE && is_null($inputData->differences_to))
            return;

        if (!empty($emailFrom) && !empty($client->email)) {
            $subject = __('complaintClientEmailSubject', $templateData, $inputData->resellerId);
            $message = __('complaintClientEmailBody', $templateData, $inputData->resellerId);
            MessagesClient::sendMessage([
                0 => [ // MessageTypes::EMAIL
                    'emailFrom' => $emailFrom,
                    'emailTo' => $client->email,
                    'subject' => $subject,
                    'message' => $message,
                ],
            ], $inputData->resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $inputData->differences_to);
            $result->notificationClientByEmail = true;
        }

        if (!empty($client->mobile)) {
            $error = '';
            $res = NotificationManager::send($inputData->resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $inputData->differences_to, $templateData, $error);
            if ($res) {
                $result->notificationClientBySms->isSent = true;
            }
            if (!empty($error)) {
                $result->notificationClientBySms->message = $error;
            }
        }

    }
}

class InputData
{

    public int|null $differences_from = null;
    public int|null $differences_to = null;

    public function __construct(
        public int        $resellerId,
        public int        $notificationType,
        public Contractor $reseller,
        public Contractor $client,
        public Contractor $creator,
        public Contractor $expert,
    )
    {
    }

}


class ResultOperation
{
    public bool $notificationEmployeeByEmail = false;
    public bool $notificationClientByEmail = false;

    public notificationClientBySms $notificationClientBySms;

    public function __construct()
    {
        $this->notificationClientBySms = new notificationClientBySms();
    }

    public function ToArray(): array
    {
        return [
            'notificationEmployeeByEmail' => $this->notificationEmployeeByEmail,
            'notificationClientByEmail' => $this->notificationClientByEmail,
            'notificationClientBySms' => $this->notificationClientBySms->ToArray()
        ];
    }
}

class notificationClientBySms
{
    public bool $isSent = false;

    public string $message = '';

    public function ToArray(): array
    {
        return [
            'isSent' => $this->isSent,
            'message' => $this->message
        ];
    }
}

/**
 * Похоже метод получения заполненного шаблона
 * @param string $templateName
 * @param array|null $templateData
 * @param int $resellerId
 * @return string
 */
function __(string $templateName, array|null $templateData, int $resellerId): string
{
    $template = getTemplateContent($templateName, $resellerId);

    if (is_array($templateData))
        return str_replace(array_keys($templateData), $templateData, $template);
    else
        return $template;
}

/**
 * Заглушка
 * @param string $templateName
 * @param int $resellerId
 * @return string
 */
function getTemplateContent(string $templateName, int $resellerId): string
{
    return "content $templateName $resellerId";
}
