<?php
/*
* @version 0.2 (исправлено для PHP 8.4)
*/
global $session;

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$qry = "1";

// Фильтр по названию (TITLE)
global $title;
if (!empty($title)) {
    $qry .= " AND TITLE LIKE '%" . DBSafe($title) . "%'";
    $out['TITLE'] = $title;
}

// Восстановление сохранённого запроса из сессии
global $save_qry;
if ($save_qry) {
    $qry = isset($session->data['wol_qry']) ? $session->data['wol_qry'] : "1";
} else {
    $session->data['wol_qry'] = $qry;
}

if (!$qry) $qry = "1";

// Обработка сортировки
global $sortby_snmpdevices;
$sort_field = 'TITLE'; // значение по умолчанию
$sort_order = '';      // ASC по умолчанию

if (!empty($sortby_snmpdevices)) {
    // Проверяем, содержит ли строка ' DESC'
    if (strpos($sortby_snmpdevices, ' DESC') !== false) {
        $sort_field = str_replace(' DESC', '', $sortby_snmpdevices);
        $sort_order = ' DESC';
    } else {
        $sort_field = $sortby_snmpdevices;
        $sort_order = '';
    }
    // Сохраняем в сессию с обновлённым порядком
    $session->data['wol_sort'] = $sort_field . $sort_order;
} else {
    // Если сортировка не задана, берём из сессии или устанавливаем по умолчанию
    if (isset($session->data['wol_sort'])) {
        $sortby_snmpdevices = $session->data['wol_sort'];
        // Повторно разбираем, чтобы получить поле и порядок
        if (strpos($sortby_snmpdevices, ' DESC') !== false) {
            $sort_field = str_replace(' DESC', '', $sortby_snmpdevices);
            $sort_order = ' DESC';
        } else {
            $sort_field = $sortby_snmpdevices;
            $sort_order = '';
        }
    } else {
        $sort_field = 'TITLE';
        $sort_order = '';
        $sortby_snmpdevices = 'TITLE';
    }
}

// Безопасное формирование ORDER BY (разрешённые поля)
$allowed_sort_fields = ['TITLE', 'MAC', 'IPADDR', 'ONLINE', 'VENDOR']; // добавьте нужные
if (!in_array($sort_field, $allowed_sort_fields)) {
    $sort_field = 'TITLE';
    $sort_order = '';
}

$order_by = "`$sort_field`$sort_order";
$out['SORTBY'] = $sortby_snmpdevices; // для шаблона

// Выполнение запроса
$res = SQLSelect("SELECT wol_devices.* FROM wol_devices WHERE $qry ORDER BY $order_by");

if (isset($res[0]['ID'])) {
    // Функция colorizeArray должна быть определена в ядре, если нет – заменяем на обычный массив
    if (function_exists('colorizeArray')) {
        colorizeArray($res);
    }
    $out['RESULT'] = $res;
}
?>