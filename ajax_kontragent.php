<?php
/* ajax_kontragent.php */

/* Самая первая строка, до любых других операторов */
error_reporting(E_ALL & ~E_STRICT & ~E_NOTICE); /* Игнорируем Strict Standards и Notice */
ini_set('display_errors', 0); /* Отключаем вывод ошибок на экран. Для отладки можно временно поставить 1. */

/* Подключение к базе данных через класс Config из configuration.php */
/* Используем абсолютный путь для надежности */
/* ИСПРАВЛЕНО: Условное подключение для совместимости с PHPUnit */
if (defined('PROJECT_ROOT')) {
    require_once PROJECT_ROOT . "/configuration.php";
} else {
    /* Fallback для веб-среды, где PROJECT_ROOT может быть не определен */
    require_once $_SERVER['DOCUMENT_ROOT'] . "/configuration.php";
}

/* Инициализация подключения к базе данных */
$dbh = null;
try {
    /* ИСПРАВЛЕНО: Используем статический метод для получения PDO-инстанса */
    $dbh = Config::getDbh();
} catch (Exception $e) { /* Ловим общий Exception, так как Config::getDbh() может бросить PDOException */
    /* В случае ошибки подключения, если это не тестовая среда, выводим сообщение и прерываем выполнение */
    if (!defined('PHPUNIT_TEST_ENVIRONMENT')) {
        error_log("Database connection error in ajax_kontragent.php: " . $e->getMessage()); /* Ошибка подключения к БД */
        echo json_encode(array('success' => false, 'message' => 'Ошибка сервера при подключении к БД.'));
        exit;
    } else {
        /* В тестовой среде просто бросаем исключение, чтобы тест мог его поймать */
        throw $e;
    }
}

/* ИСПРАВЛЕНО: Оборачиваем функции в if (!function_exists()) */
if (!function_exists('build_full_sql_query')) {
    /**
     * Builds a SQL query string with parameters substituted for logging/debugging.
     * WARNING: This function is for logging/debugging ONLY and does NOT protect against SQL injection.
     * Do NOT use the output of this function directly in a database query.
     * Use PDO prepared statements for actual query execution.
     * @param PDO $dbh The PDO database handle, used for quoting values.
     * @param string $sql The SQL query template with named placeholders (e.g., :param_name).
     * @param array $params An associative array of parameters to substitute.
     * @return string The SQL query with parameters substituted.
     */
    function build_full_sql_query($dbh, $sql, $params) {
        $keys = array();
        $values = array();

        if (!is_array($params) || empty($params)) {            return $sql;
        }

        foreach ($params as $key => $value) {
            /* Для именованных параметров (например, :name) */
            $param_key = (substr($key, 0, 1) === ':') ? $key : ':' . $key;
            $keys[] = $param_key;

            /* Экранирование значений в зависимости от типа */
            if (is_string($value)) {
                $values[] = $dbh->quote($value); /* Используем quote для строк */
            } elseif (is_int($value) || is_float($value)) {
                /* ИСПРАВЛЕНО: Числа не должны цитироваться, просто подставляем числовое значение */
                $values[] = strval($value); /* Преобразуем в строку для str_replace, но без кавычек */
            } elseif (is_null($value)) {
                $values[] = 'NULL'; /* NULL как ключевое слово SQL */
            } elseif (is_bool($value)) {
                $values[] = $value ? '1' : '0'; /* Булевы значения как 1 или 0 */
            } else {
                /* Для других типов преобразуем в строку и цитируем */
                $values[] = $dbh->quote(strval($value));
            }
        }
        /* Заменяем плейсхолдеры на значения */
        /* Используем str_replace, так как PDO не раскрывает связанный SQL */
        return str_replace($keys, $values, $sql);
    }
}

if (!function_exists('logKontragentActivity')) {
    /*
     * Функция для логирования действий контрагента в таблицу aa_kontragent_log.
     * SQL-запрос должен быть включен в $log_data под ключом 'sql_query_full'.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $kontragent_id ID контрагента, к которому относится действие (0 для нового).
     * @param string $action_type Тип действия (напр., FORM_LOAD_EXISTING, SAVE_SUCCESS_INSERT).
     * @param array $log_data Данные, связанные с действием, в формате ассоциативного массива (может включать 'sql_query_full').
     * @param int $user_id ID пользователя, выполнившего действие.
     */
    function logKontragentActivity($dbh, $kontragent_id, $action_type, $log_data, $user_id) {
        try {
            $sql = "INSERT INTO work.aa_kontragent_log (kontragent_id, action_type, log_data_json, user_id) VALUES (:kontragent_id, :action_type, :log_data_json, :user_id)";
            $stmt = $dbh->prepare($sql);
            $stmt->execute(array(
                ':kontragent_id' => $kontragent_id,
                ':action_type' => $action_type,
                ':log_data_json' => json_encode($log_data),
                ':user_id' => $user_id
            ));
        } catch (PDOException $e) {
            /* Логируем ошибку, но не прерываем выполнение, так как это вспомогательная функция */
            error_log("Ошибка логирования действия контрагента: " . $e->getMessage());
        }
    }
}

if (!function_exists('getCurrentUserId')) {
    /*
     * Функция для получения ID текущего пользователя.
     * TODO: В реальном приложении эта функция должна получать ID из сессии или системы аутентификации.
     * @return int ID текущего пользователя.
     */
    function getCurrentUserId() {
        /* Пример: возвращаем статичный ID пользователя 1. */
        return 1;
    }
}

if (!function_exists('getKontragentType')) {
    /*
     * Функция для получения типа контрагента из входных данных.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return int Тип контрагента (1=Физлицо, 2=Юрлицо, 3=Агентство).
     */
    function getKontragentType($input_data) {
        $kontragent_type = 1; /* По умолчанию Физлицо */
        if (isset($input_data['kontragent'])) {
            $kontragent_type = intval($input_data['kontragent']);
        } elseif (isset($input_data['kontragent_type'])) {
            $kontragent_type = intval($input_data['kontragent_type']);
        }
        /* Убедимся, что тип в допустимых пределах (1, 2, 3) */
        if (!in_array($kontragent_type, array(1, 2, 3))) {
            $kontragent_type = 1;
        }
        return $kontragent_type;
    }
}

if (!function_exists('getEditId')) {
    /*
     * Функция для получения ID редактируемого контрагента из входных данных.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return int ID контрагента (0 для нового).
     */
    function getEditId($input_data) {
        $edit_id = 0; /* По умолчанию новый контрагент */
        if (isset($input_data['id_people'])) {
            $edit_id = intval($input_data['id_people']);
        } elseif (isset($input_data['edit_id'])) {
            $edit_id = intval($input_data['edit_id']);
        }        return $edit_id;
    }
}

/* ИСПРАВЛЕНО: Обертка для основного обработчика AJAX запросов */
if (!function_exists('processAjaxKontragentRequest')) {
    /**
     * Обрабатывает AJAX-запрос и возвращает JSON-строку ответа.
     * Это центральная функция, которую можно вызывать как из веб-среды, так и из тестов.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function processAjaxKontragentRequest($dbh, $current_user_id, $input_data) {
        $action = isset($input_data['action']) ? $input_data['action'] : '';
        $response = array('success' => false, 'message' => 'Неизвестное действие: ' . $action);

        switch ($action) {
            case 'get_kontragent_list':
                $response = handleGetKontragentList($dbh, $current_user_id, $input_data);
                break;
            case 'get_kontragent_data':
                $response = handleGetKontragentData($dbh, $current_user_id, $input_data);
                break;
            case 'save_kontragent':
                $response = handleSaveKontragent($dbh, $current_user_id, $input_data);
                break;
            case 'get_contracts':
                $response = handleGetContracts($dbh, $current_user_id, $input_data);
                break;
            case 'save_contract':
                $response = handleSaveContract($dbh, $current_user_id, $input_data);
                break;
            case 'delete_contract':
                $response = handleDeleteContract($dbh, $current_user_id, $input_data);
                break;
            case 'get_regions':
                $response = handleGetRegions($dbh);
                break;
            case 'get_cities_by_region':
                $response = handleGetCitiesByRegion($dbh, $input_data);
                break;            case 'mark_kontragent_deleted':
            $response = handleMarkKontragentDeleted($dbh, $current_user_id, $input_data);
            break;
            case 'restore_kontragent':
                $response = handleRestoreKontragent($dbh, $current_user_id, $input_data);
                break;
            case 'log_client_notify':
                $response = handleLogClientNotify($dbh, $current_user_id, $input_data);
                break;
            default:
                $response = array('success' => false, 'message' => 'Неизвестное действие: ' . $action);
                break;
        }
        return $response; /* Возвращаем массив, а не JSON-строку */
    }
}

/* ИСПРАВЛЕНО: Все функции-обработчики теперь возвращают массив, а не echo/exit */

if (!function_exists('handleGetKontragentList')) {
    /*
     * Обрабатывает запрос на получение списка контрагентов для DevExtreme DataGrid.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleGetKontragentList($dbh, $current_user_id, $input_data) {
        $kontragent_type = getKontragentType($input_data);

        $skip = isset($input_data['skip']) ? intval($input_data['skip']) : 0;
        $take = isset($input_data['take'])  ? intval($input_data['take']) : 100; /* NEW: Выборка данных по 100 строк */
        $filter = isset($input_data['filter']) ? $input_data['filter'] : null;
        $searchValue = isset($input_data['searchValue']) ? $input_data['searchValue'] : null;
        $sort = isset($input_data['sort']) ? $input_data['sort'] : null;

        /* NEW: Получаем статус фильтрации по удаленным записям */
        $is_delete_status = isset($input_data['is_delete_status']) ? $input_data['is_delete_status'] : 'active'; /* 'active', 'all', 'deleted' */
        $where_clauses = array();
        $bind_params = array();

        /* Применяем фильтр по статусу удаления */
        if ($is_delete_status === 'active') {
            $where_clauses[] = "k.is_delete = 0";
        } elseif ($is_delete_status === 'deleted') {
            $where_clauses[] = "k.is_delete = 1";
        }
        /* 'all' означает, что фильтр по is_delete не применяется */

        /* Фильтрация по типу контрагента */
        switch ($kontragent_type) {
            case 1: /* Физлицо */
                $where_clauses[] = "k.typeuser = 'Fiz'";
                break;
            case 2: /* Юрлицо */
                $where_clauses[] = "k.typeuser IS NULL AND k.is_operator = 0"; /* Предполагаем, что Юрлицо - это не оператор и не физлицо */
                break;
            case 3: /* Агентство */
                $where_clauses[] = "k.typeuser = 'Agent'";
                break;
        }

        /* Полнотекстовый поиск (searchPanel) */
        if (!empty($searchValue)) {
            $search_fields = array('k.fio', 'k.name', 'k.shortname', 'k.address', 'k.inn', 'k.phone', 'k.email');
            $search_conditions = array();
            foreach ($search_fields as $field) {
                $search_conditions[] = $field . " LIKE :search_value";
            }
            $where_clauses[] = "(" . implode(" OR ", $search_conditions) . ")";
            $bind_params[':search_value'] = '%' . $searchValue . '%';
        }

        /* Фильтрация по колонкам (filterRow, headerFilter) */
        /* DevExtreme filter array: [["name", "contains", "test"], "and", ["inn", "=", "123"]] */
        /* Для простоты, обрабатываем только простые фильтры "поле, оператор, значение" */
        /* Более сложные фильтры (с "and"/"or") требуют рекурсивного парсера */
        if (!empty($filter) && is_array($filter)) {
            /* Если это простой фильтр типа ["dataField", "operator", "value"] */
            if (count($filter) == 3 && is_string($filter[0])) {
                $field = $filter[0];
                $operator = $filter[1];
                $value = $filter[2];

                $db_field = '';
                switch ($field) {
                    case 'fio': $db_field = 'k.fio'; break;
                    case 'name': $db_field = 'k.name'; break;
                    case 'shortname': $db_field = 'k.shortname'; break;
                    case 'inn': $db_field = 'k.inn'; break;
                    case 'phone': $db_field = 'k.phone'; break;
                    case 'email': $db_field = 'k.email'; break;
                    case 'address': $db_field = 'k.address'; break;
                    /* Добавьте другие поля по необходимости */
                }

                if (!empty($db_field)) {
                    $param_name = ':' . str_replace('.', '_', $field); /* Заменяем точку для имени параметра */
                    switch ($operator) {
                        case 'contains':
                            $where_clauses[] = $db_field . " LIKE " . $param_name;
                            $bind_params[$param_name] = '%' . $value . '%';
                            break;
                        case '=':
                            $where_clauses[] = $db_field . " = " . $param_name;
                            $bind_params[$param_name] = $value;
                            break;
                        /* Добавьте другие операторы, такие как 'startswith', 'endswith', '>', '<', '>=' <= */
                    }
                }
            }
            /* TODO: Реализовать более сложный парсер для массивов фильтров с "and"/"or" */
        }

        $where_sql = count($where_clauses) > 0 ? " WHERE " . implode(" AND ", $where_clauses) : "";
        /* Формирование ORDER BY */
        /* ИСПРАВЛЕНО: Сортировка по умолчанию изменена на k.id ASC для предсказуемости в тестах */
        $order_sql = " ORDER BY k.id ASC";        if (!empty($sort) && is_array($sort)) {
            $sort_columns = array();
            foreach ($sort as $s_item) {
                $data_field = isset($s_item['selector']) ? $s_item['selector'] : '';
                $desc = isset($s_item['desc']) ? $s_item['desc'] : false;
                if (!empty($data_field)) {
                    $sort_columns[] = "k." . $data_field . ($desc ? " DESC" : " ASC");
                }
            }
            if (count($sort_columns) > 0) {
                $order_sql = " ORDER BY " . implode(", ", $sort_columns);
            }
        }
        $log_data_payload = array(
            'type' => $kontragent_type,
            'skip' => $skip,
            'take' => $take,
            'search' => $searchValue,
            'filter' => $filter,
            'is_delete_status' => $is_delete_status
        );

        try {
            /* Общее количество записей для пагинации */
            $sql_count = "SELECT COUNT(k.id) FROM work.aa_kontragent k" . $where_sql;
            $stmt_count = $dbh->prepare($sql_count);
            $stmt_count->execute($bind_params);
            $total_count = $stmt_count->fetchColumn();
            $log_data_payload['sql_count_query_template'] = $sql_count; /* NEW: Логируем шаблон SQL-запроса для COUNT */
            $log_data_payload['sql_count_query_full'] = build_full_sql_query($dbh, $sql_count, $bind_params); /* NEW: Логируем полный SQL-запрос для COUNT */            /* Запрос данных */
            /* NEW: Добавлено k.is_operator в выборку для списка */
            $sql_data = "SELECT k.id, k.fio, k.name, k.shortname, k.inn, k.phone, k.email, k.address, k.is_delete, k.is_operator FROM work.aa_kontragent k" . $where_sql . $order_sql . " LIMIT :skip, :take";
            $stmt_data = $dbh->prepare($sql_data);
            $stmt_data->bindParam(':skip', $skip, PDO::PARAM_INT);
            $stmt_data->bindParam(':take', $take, PDO::PARAM_INT);
            foreach ($bind_params as $key => $value) {
                $stmt_data->bindValue($key, $value);
            }
            $stmt_data->execute();
            $data = $stmt_data->fetchAll(PDO::FETCH_ASSOC);
            $log_data_payload['sql_data_query_template'] = $sql_data; /* NEW: Логируем шаблон SQL-запроса для данных */
            $log_data_payload['sql_data_query_full'] = build_full_sql_query($dbh, $sql_data, array_merge($bind_params, array('skip' => $skip, 'take' => $take))); /* NEW: Логируем полный SQL-запрос для данных */

            logKontragentActivity($dbh, 0, 'GET_KONTRAGENT_LIST', $log_data_payload, $current_user_id);

            return array(
                'success' => true,
                'data' => $data,
                'totalCount' => $total_count
            );
        } catch (PDOException $e) {
            error_log("GET_KONTRAGENT_LIST_FAILED DB Error: " . $e->getMessage());
            logKontragentActivity($dbh, 0, 'GET_KONTRAGENT_LIST_FAILED', array_merge($log_data_payload, array('error' => $e->getMessage())), $current_user_id);
            return array('success' => false, 'message' => 'Ошибка при загрузке списка контрагентов: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleGetKontragentData')) {
    /*
     * Обрабатывает запрос на получение данных одного контрагента для формы редактирования.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleGetKontragentData($dbh, $current_user_id, $input_data) {
        $edit_id = getEditId($input_data);        if ($edit_id == 0) {
            return array('success' => false, 'message' => 'ID контрагента не указан для редактирования.');
        }

        $sql = "SELECT k.*, ko.reestrnum, ko.website, ko.membership, ko.amount_financial_support, ko.financial_support,
                       ko.method_financial_support, ko.document, ko.term_financial_support, ko.firm_name_financial_support,
                       ko.adress_firm_financial_support, ko.zipadress_firm_financial_support, ko.scope_operator,
                       ko.order_number, ko.order_date, ko.certificate_number
                FROM work.aa_kontragent k
                LEFT JOIN work.aa_kontragent_operator ko ON k.id = ko.id_kontragent
                WHERE k.id = :id AND k.is_delete = 0";
        $params = array(':id' => $edit_id);
        $log_data_base = array(
            'sql_query_template' => $sql, /* NEW: Шаблон SQL-запроса */
            'sql_query_full' => build_full_sql_query($dbh, $sql, $params) /* NEW: Полный SQL-запрос */
        );

        try {
            $stmt = $dbh->prepare($sql);
            $stmt->execute($params);
            $kontragent_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($kontragent_data) {
                /* Форматируем даты для DevExtreme DateBox */
                if (!empty($kontragent_data['birthday'])) {
                    $kontragent_data['birthday'] = date('Y-m-d', strtotime($kontragent_data['birthday']));
                }
                if (!empty($kontragent_data['passport_data'])) {
                    $kontragent_data['passport_data'] = date('Y-m-d', strtotime($kontragent_data['passport_data']));
                }
                if (!empty($kontragent_data['order_date'])) {
                    $kontragent_data['order_date'] = date('Y-m-d', strtotime($kontragent_data['order_date']));
                }

                logKontragentActivity($dbh, $edit_id, 'FORM_LOAD_EXISTING', array_merge($log_data_base, array('data_loaded' => true)), $current_user_id); /* NEW: Передаем SQL */
                return array('success' => true, 'data' => $kontragent_data);
            } else {
                logKontragentActivity($dbh, $edit_id, 'FORM_LOAD_FAILED_NOT_FOUND', $log_data_base, $current_user_id); /* NEW: Передаем SQL */
                return array('success' => false, 'message' => 'Контрагент с указанным ID не найден или удален.');
            }
        } catch (PDOException $e) {
            logKontragentActivity($dbh, $edit_id, 'FORM_LOAD_FAILED_DB_ERROR', array_merge($log_data_base, array('error' => $e->getMessage())), $current_user_id); /* NEW: Передаем SQL */
            return array('success' => false, 'message' => 'Ошибка при загрузке данных контрагента: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleSaveKontragent')) {
    /*
     * Обрабатывает запрос на сохранение (добавление или обновление) данных контрагента.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleSaveKontragent($dbh, $current_user_id, $input_data) {
        $data = $input_data;
        $edit_id = getEditId($input_data);
        $kontragent_type_numeric = getKontragentType($input_data); /* Используем числовой тип для логики */

        $response = array('success' => false, 'message' => 'Неизвестная ошибка.');        /* Базовая валидация */
        if (empty($data['name']) && empty($data['fio'])) {
            return array('success' => false, 'message' => 'Наименование или ФИО контрагента обязательны.');
        }
        try {
            $dbh->beginTransaction();

            /* Определяем typeuser на основе kontragent_type_numeric */
            $typeuser = null;
            if ($kontragent_type_numeric == 1) {
                $typeuser = 'Fiz';
            } elseif ($kontragent_type_numeric == 3) {
                $typeuser = 'Agent';
            }
            /* Подготовка общих параметров для aa_kontragent */
            $params_main = array(
                ':name' => isset($data['name']) ? $data['name'] : null,
                ':shortname' => isset($data['shortname']) ? $data['shortname'] : null,
                ':inn' => isset($data['inn']) ? $data['inn'] : null,                ':kpp' => isset($data['kpp']) ? $data['kpp'] : null,
                ':ogrn' => isset($data['ogrn']) ? $data['ogrn'] : '', /* OGRN is NOT NULL in DB, default to empty string */
                ':bank' => isset($data['bank']) ? $data['bank'] : null,
                ':bik' => isset($data['bik']) ? $data['bik'] : null,
                ':rs' => isset($data['rs']) ? $data['rs'] : null,
                ':ks' => isset($data['ks']) ? $data['ks'] : null,
                ':ua' => isset($data['ua']) ? $data['ua'] : null,
                ':direktor_fio' => isset($data['direktor_fio']) ? $data['direktor_fio'] : null,
                ':direktor_status' => isset($data['direktor_status']) ? $data['direktor_status'] : null,
                ':buh_fio' => isset($data['buh_fio']) ? $data['buh_fio'] : null,
                ':tax_own' => isset($data['tax_own']) ? intval($data['tax_own']) : 0,
                ':tax' => isset($data['tax']) ? intval($data['tax']) : 0,
                ':id_city' => isset($data['id_city']) ? intval($data['id_city']) : 0,
                ':id_region' => isset($data['id_region']) ? intval($data['id_region']) : 0,
                ':isOkved' => isset($data['isOkved']) ? $data['isOkved'] : null,
                ':can_create_subagent' => isset($data['can_create_subagent']) ? intval($data['can_create_subagent']) : 0,
                ':allow_print_vaucher' => isset($data['allow_print_vaucher']) ? intval($data['allow_print_vaucher']) : 0,
                ':is_operator' => isset($data['is_operator']) ? intval($data['is_operator']) : 0,
                ':birthday' => isset($data['birthday']) && !empty($data['birthday']) ? date('Y-m-d', strtotime($data['birthday'])) : null,
                ':phone' => isset($data['phone']) ? $data['phone'] : null,
                ':phone1' => isset($data['phone1']) ? $data['phone1'] : null,
                ':phone2' => isset($data['phone2']) ? $data['phone2'] : null,
                ':email' => isset($data['email']) ? $data['email'] : null,
                ':zipcode' => isset($data['zipcode']) ? $data['zipcode'] : null,
                ':passport_s' => isset($data['passport_s']) ? $data['passport_s'] : null,
                ':passport_n' => isset($data['passport_n']) ? $data['passport_n'] : null,
                ':passport_data' => isset($data['passport_data']) && !empty($data['passport_data']) ? date('Y-m-d', strtotime($data['passport_data'])) : null,
                ':passport_who' => isset($data['passport_who']) ? $data['passport_who'] : null,
                ':discount_num' => isset($data['discount_num']) ? $data['discount_num'] : null,
                ':discount' => isset($data['discount']) ? intval($data['discount']) : null,
                ':fax' => isset($data['fax']) ? $data['fax'] : null,
                ':user_id' => $current_user_id, /* ID пользователя, создавшего запись */
                ':owner' => 0, /* owner устанавливается здесь, так как он read-only из формы */
                ':typeuser' => $typeuser,
                ':fio' => isset($data['fio']) ? $data['fio'] : null,
                ':address' => isset($data['address']) ? $data['address'] : null,
                ':city_fizik' => isset($data['city_fizik']) ? $data['city_fizik'] : null,
                ':occupation' => isset($data['occupation']) ? $data['occupation'] : null,
                ':workplace' => isset($data['workplace']) ? $data['workplace'] : null,
                ':id_user_last_update' => $current_user_id /* ID пользователя, обновившего запись */
            );

            if ($edit_id == 0) { /* INSERT нового контрагента */
                $sql_insert_main = "INSERT INTO work.aa_kontragent (
                    name, shortname, inn, kpp, ogrn, bank, rs, ks, bik, ua,
                    direktor_fio, direktor_status, buh_fio, tax_own, tax,
                    id_city, id_region, isOkved, can_create_subagent, allow_print_vaucher,
                    is_operator, birthday, phone, phone1, phone2, email, zipcode,
                    passport_s, passport_n, passport_data, passport_who,
                    discount_num, discount, fax, owner, user_id, id_user_last_update,
                    typeuser, fio, address, city_fizik, occupation, workplace
                ) VALUES (
                    :name, :shortname, :inn, :kpp, :ogrn, :bank, :rs, :ks, :bik, :ua,
                    :direktor_fio, :direktor_status, :buh_fio, :tax_own, :tax,
                    :id_city, :id_region, :isOkved, :can_create_subagent, :allow_print_vaucher,
                    :is_operator, :birthday, :phone, :phone1, :phone2, :email, :zipcode,
                    :passport_s, :passport_n, :passport_data, :passport_who,
                    :discount_num, :discount, :fax, :owner, :user_id, :id_user_last_update,
                    :typeuser, :fio, :address, :city_fizik, :occupation, :workplace
                )";
                $stmt_insert_main = $dbh->prepare($sql_insert_main);

                if ($stmt_insert_main->execute($params_main)) {
                    $new_kontragent_id = $dbh->lastInsertId();
                    $response = array('success' => true, 'message' => 'Новый контрагент успешно добавлен.', 'new_id' => $new_kontragent_id);
                    logKontragentActivity($dbh, $new_kontragent_id, 'SAVE_SUCCESS_INSERT', array_merge($data, array('sql_query_template' => $sql_insert_main, 'sql_query_full' => build_full_sql_query($dbh, $sql_insert_main, $params_main))), $current_user_id); /* NEW: Логируем SQL */
                    $edit_id = $new_kontragent_id; /* Обновляем edit_id для возможного сохранения данных оператора */

                    /* Если контрагент - Агентство и помечен как оператор, добавляем данные оператора */
                    if ($kontragent_type_numeric === 3 && isset($data['is_operator']) && $data['is_operator'] == 1) {
                        $operator_params = array(
                            ':reestrnum' => isset($data['reestrnum']) ? $data['reestrnum'] : '',
                            ':website' => isset($data['website']) ? $data['website'] : '',
                            ':membership' => isset($data['membership']) ? $data['membership'] : '',
                            ':amount_financial_support' => isset($data['amount_financial_support']) ? intval($data['amount_financial_support']) : 0,
                            ':financial_support' => isset($data['financial_support']) ? intval($data['financial_support']) : 0,
                            ':method_financial_support' => isset($data['method_financial_support']) ? $data['method_financial_support'] : '',                            ':document' => isset($data['document']) ? $data['document'] : '',
                            ':term_financial_support' => isset($data['term_financial_support']) ? $data['term_financial_support'] : '',
                            ':firm_name_financial_support' => isset($data['firm_name_financial_support']) ? $data['firm_name_financial_support'] : '',
                            ':adress_firm_financial_support' => isset($data['adress_firm_financial_support']) ? $data['adress_firm_financial_support'] : '',
                            ':zipadress_firm_financial_support' => isset($data['zipadress_firm_financial_support']) ? $data['zipadress_firm_financial_support'] : '',
                            ':scope_operator' => isset($data['scope_operator']) ? $data['scope_operator'] : '',
                            ':order_number' => isset($data['order_number']) ? $data['order_number'] : '', /* NOT NULL DEFAULT '' */
                            ':order_date' => (isset($data['order_date']) && !empty($data['order_date'])) ? date('Y-m-d', strtotime($data['order_date'])) : '', /* NOT NULL DEFAULT '' */
                            ':certificate_number' => isset($data['certificate_number']) ? $data['certificate_number'] : '',
                            ':id_kontragent' => $edit_id
                        );

                        $sql_insert_operator = "INSERT INTO work.aa_kontragent_operator (id_kontragent, reestrnum, website, membership, amount_financial_support, financial_support, method_financial_support, document, term_financial_support, firm_name_financial_support, adress_firm_financial_support, zipadress_firm_financial_support, scope_operator, order_number, order_date, certificate_number) VALUES (
                            :id_kontragent, :reestrnum, :website, :membership, :amount_financial_support, :financial_support,
                            :method_financial_support, :document, :term_financial_support, :firm_name_financial_support,
                            :adress_firm_financial_support, :zipadress_firm_financial_support, :scope_operator,
                            :order_number, :order_date, :certificate_number
                        )";
                        $stmt_insert_operator = $dbh->prepare($sql_insert_operator);
                        if ($stmt_insert_operator->execute($operator_params)) {
                            logKontragentActivity($dbh, $edit_id, 'OPERATOR_INSERT_SUCCESS', array_merge($operator_params, array('sql_query_template' => $sql_insert_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_insert_operator, $operator_params))), $current_user_id); /* NEW: Логируем SQL */
                        } else {
                            $error_info = $stmt_insert_operator->errorInfo();
                            error_log("OPERATOR_INSERT_FAILED_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql_insert_operator, $operator_params)); /* NEW: Логирование SQL ошибки */
                            logKontragentActivity($dbh, $edit_id, 'OPERATOR_INSERT_FAILED_EXECUTE', array_merge($operator_params, array('error_info' => $error_info, 'sql_query_template' => $sql_insert_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_insert_operator, $operator_params))), $current_user_id); /* NEW: Логируем SQL */
                            $response['success'] = false;
                            $response['message'] .= ' Ошибка при добавлении данных оператора: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL');
                        }
                    }
                } else {
                    $error_info = $stmt_insert_main->errorInfo();
                    error_log("SAVE_FAILED_INSERT_MAIN_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql_insert_main, $params_main)); /* NEW: Логирование SQL ошибки */
                    $response['success'] = false;
                    $response['message'] = 'Ошибка при добавлении основных данных контрагента (execute failed): ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL');
                    logKontragentActivity($dbh, 0, 'SAVE_FAILED_INSERT_MAIN_EXECUTE', array_merge($data, array('error_info' => $error_info, 'sql_query_template' => $sql_insert_main, 'sql_query_full' => build_full_sql_query($dbh, $sql_insert_main, $params_main))), $current_user_id); /* NEW: Логируем SQL */
                }
            } else { /* UPDATE существующего контрагента */
                /* ИСПРАВЛЕНО: Удалены user_id и owner из UPDATE-запроса, так как они read-only. */
                $sql_update_main = "UPDATE work.aa_kontragent SET
                    name = :name, shortname = :shortname, inn = :inn, kpp = :kpp, ogrn = :ogrn,                    bank = :bank, rs = :rs, ks = :ks, bik = :bik, ua = :ua,
                    direktor_fio = :direktor_fio, direktor_status = :direktor_status, buh_fio = :buh_fio,
                    tax_own = :tax_own, tax = :tax, id_city = :id_city, id_region = :id_region,
                    isOkved = :isOkved, can_create_subagent = :can_create_subagent,
                    allow_print_vaucher = :allow_print_vaucher, is_operator = :is_operator,
                    birthday = :birthday, phone = :phone, phone1 = :phone1, phone2 = :phone2,
                    email = :email, zipcode = :zipcode, passport_s = :passport_s,
                    passport_n = :passport_n, passport_data = :passport_data, passport_who = :passport_who,
                    discount_num = :discount_num, discount = :discount, fax = :fax,
                    ua = :ua, id_user_last_update = :id_user_last_update,
                    typeuser = :typeuser, fio = :fio, address = :address, city_fizik = :city_fizik,
                    occupation = :occupation, workplace = :workplace
                WHERE id = :id";
                $stmt_update_main = $dbh->prepare($sql_update_main);
                /* Формируем параметры для UPDATE. Исключаем owner и user_id. */
                $params_update_main = array(
                    ':name' => isset($data['name']) ? $data['name'] : null,
                    ':shortname' => isset($data['shortname']) ? $data['shortname'] : null,
                    ':inn' => isset($data['inn']) ? $data['inn'] : null,
                    ':kpp' => isset($data['kpp']) ? $data['kpp'] : null,
                    ':ogrn' => isset($data['ogrn']) ? $data['ogrn'] : '',
                    ':bank' => isset($data['bank']) ? $data['bank'] : null,
                    ':bik' => isset($data['bik']) ? $data['bik'] : null,
                    ':rs' => isset($data['rs']) ? $data['rs'] : null,
                    ':ks' => isset($data['ks']) ? $data['ks'] : null,
                    ':direktor_fio' => isset($data['direktor_fio']) ? $data['direktor_fio'] : null,
                    ':direktor_status' => isset($data['direktor_status']) ? $data['direktor_status'] : null,
                    ':buh_fio' => isset($data['buh_fio']) ? $data['buh_fio'] : null,
                    ':tax_own' => isset($data['tax_own']) ? intval($data['tax_own']) : 0,
                    ':tax' => isset($data['tax']) ? intval($data['tax']) : 0,
                    ':id_city' => isset($data['id_city']) ? intval($data['id_city']) : 0,
                    ':id_region' => isset($data['id_region']) ? intval($data['id_region']) : 0,
                    ':isOkved' => isset($data['isOkved']) ? $data['isOkved'] : null,
                    ':can_create_subagent' => isset($data['can_create_subagent']) ? intval($data['can_create_subagent']) : 0,
                    ':allow_print_vaucher' => isset($data['allow_print_vaucher']) ? intval($data['allow_print_vaucher']) : 0,
                    ':is_operator' => isset($data['is_operator']) ? intval($data['is_operator']) : 0,
                    ':birthday' => isset($data['birthday']) && !empty($data['birthday']) ? date('Y-m-d', strtotime($data['birthday'])) : null,                    ':phone' => isset($data['phone']) ? $data['phone'] : null,
                    ':phone1' => isset($data['phone1']) ? $data['phone1'] : null,
                    ':phone2' => isset($data['phone2']) ? $data['phone2'] : null,
                    ':email' => isset($data['email']) ? $data['email'] : null,
                    ':zipcode' => isset($data['zipcode']) ? $data['zipcode'] : null,
                    ':passport_s' => isset($data['passport_s']) ? $data['passport_s'] : null,
                    ':passport_n' => isset($data['passport_n']) ? $data['passport_n'] : null,
                    ':passport_data' => isset($data['passport_data']) && !empty($data['passport_data']) ? date('Y-m-d', strtotime($data['passport_data'])) : null,
                    ':passport_who' => isset($data['passport_who']) ? $data['passport_who'] : null,
                    ':discount_num' => isset($data['discount_num']) ? $data['discount_num'] : null,
                    ':discount' => isset($data['discount']) ? intval($data['discount']) : null,
                    ':fax' => isset($data['fax']) ? $data['fax'] : null,
                    ':ua' => isset($data['ua']) ? $data['ua'] : null,
                    ':id_user_last_update' => $current_user_id,
                    ':typeuser' => $typeuser,
                    ':fio' => isset($data['fio']) ? $data['fio'] : null,
                    ':address' => isset($data['address']) ? $data['address'] : null,
                    ':city_fizik' => isset($data['city_fizik']) ? $data['city_fizik'] : null,
                    ':occupation' => isset($data['occupation']) ? $data['occupation'] : null,
                    ':workplace' => isset($data['workplace']) ? $data['workplace'] : null,
                    ':id' => $edit_id
                );

                if ($stmt_update_main->execute($params_update_main)) {
                    $response = array('success' => true, 'message' => 'Данные контрагента успешно обновлены.', 'new_id' => $edit_id);
                    logKontragentActivity($dbh, $edit_id, 'SAVE_SUCCESS_UPDATE', array_merge($data, array('sql_query_template' => $sql_update_main, 'sql_query_full' => build_full_sql_query($dbh, $sql_update_main, $params_update_main))), $current_user_id); /* NEW: Логируем SQL */

                    /* Обработка данных оператора */
                    if ($kontragent_type_numeric === 3) { /* Только для Агентства */
                        $is_operator_flag = isset($data['is_operator']) ? intval($data['is_operator']) : 0;
                        $operator_params = array(
                            ':reestrnum' => isset($data['reestrnum']) ? $data['reestrnum'] : '',
                            ':website' => isset($data['website']) ? $data['website'] : '',
                            ':membership' => isset($data['membership']) ? $data['membership'] : '',
                            ':amount_financial_support' => isset($data['amount_financial_support']) ? intval($data['amount_financial_support']) : 0,
                            ':financial_support' => isset($data['financial_support']) ? intval($data['financial_support']) : 0,
                            ':method_financial_support' => isset($data['method_financial_support']) ? $data['method_financial_support'] : '',
                            ':document' => isset($data['document']) ? $data['document'] : '',
                            ':term_financial_support' => isset($data['term_financial_support']) ? $data['term_financial_support'] : '',
                            ':firm_name_financial_support' => isset($data['firm_name_financial_support']) ? $data['firm_name_financial_support'] : '',
                            ':adress_firm_financial_support' => isset($data['adress_firm_financial_support']) ? $data['adress_firm_financial_support'] : '',                            ':zipadress_firm_financial_support' => isset($data['zipadress_firm_financial_support']) ? $data['zipadress_firm_financial_support'] : '',
                            ':scope_operator' => isset($data['scope_operator']) ? $data['scope_operator'] : '',
                            ':order_number' => isset($data['order_number']) ? $data['order_number'] : '', /* NOT NULL DEFAULT '' */
                            ':order_date' => (isset($data['order_date']) && !empty($data['order_date'])) ? date('Y-m-d', strtotime($data['order_date'])) : '', /* NOT NULL DEFAULT '' */
                            ':certificate_number' => isset($data['certificate_number']) ? $data['certificate_number'] : '',
                            ':id_kontragent' => $edit_id
                        );
                        /* Проверяем, существует ли запись оператора */
                        $sql_check_operator = "SELECT COUNT(*) FROM work.aa_kontragent_operator WHERE id_kontragent = :id_kontragent";
                        $stmt_check_operator = $dbh->prepare($sql_check_operator);
                        $stmt_check_operator->execute(array(':id_kontragent' => $edit_id));
                        $operator_exists = $stmt_check_operator->fetchColumn() > 0;
                        if ($is_operator_flag == 1) { /* Если помечен как оператор */
                            if ($operator_exists) { /* Обновляем существующую запись */
                                $sql_update_operator = "UPDATE work.aa_kontragent_operator SET
                                    reestrnum = :reestrnum, website = :website, membership = :membership,
                                    amount_financial_support = :amount_financial_support, financial_support = :financial_support,
                                    method_financial_support = :method_financial_support, document = :document,
                                    term_financial_support = :term_financial_support, firm_name_financial_support = :firm_name_financial_support,
                                    adress_firm_financial_support = :adress_firm_financial_support, zipadress_firm_financial_support = :zipadress_firm_financial_support,
                                    scope_operator = :scope_operator, order_number = :order_number,
                                    order_date = :order_date, certificate_number = :certificate_number
                                WHERE id_kontragent = :id_kontragent";
                                $stmt_update_operator = $dbh->prepare($sql_update_operator);
                                if ($stmt_update_operator->execute($operator_params)) {
                                    logKontragentActivity($dbh, $edit_id, 'OPERATOR_UPDATE_SUCCESS', array_merge($operator_params, array('sql_query_template' => $sql_update_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_update_operator, $operator_params))), $current_user_id); /* NEW: Логируем SQL */
                                } else {
                                    $error_info = $stmt_update_operator->errorInfo();                                    error_log("OPERATOR_UPDATE_FAILED_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql_update_operator, $operator_params)); /* NEW: Логирование SQL ошибки */
                                    logKontragentActivity($dbh, $edit_id, 'OPERATOR_UPDATE_FAILED_EXECUTE', array_merge($operator_params, array('error_info' => $error_info, 'sql_query_template' => $sql_update_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_update_operator, $operator_params))), $current_user_id); /* NEW: Логируем SQL */
                                    $response['success'] = false;
                                    $response['message'] .= ' Ошибка при обновлении данных оператора: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL');
                                }
                            } else { /* Добавляем новую запись оператора */
                                $sql_insert_operator = "INSERT INTO work.aa_kontragent_operator (id_kontragent, reestrnum, website, membership, amount_financial_support, financial_support, method_financial_support, document, term_financial_support, firm_name_financial_support, adress_firm_financial_support, zipadress_firm_financial_support, scope_operator, order_number, order_date, certificate_number) VALUES (
                                    :id_kontragent, :reestrnum, :website, :membership, :amount_financial_support, :financial_support,
                                    :method_financial_support, :document, :term_financial_support, :firm_name_financial_support,
                                    :adress_firm_financial_support, :zipadress_firm_financial_support, :scope_operator,
                                    :order_number, :order_date, :certificate_number
                                )";
                                $stmt_insert_operator = $dbh->prepare($sql_insert_operator);
                                if ($stmt_insert_operator->execute($operator_params)) {
                                    logKontragentActivity($dbh, $edit_id, 'OPERATOR_INSERT_SUCCESS', array_merge($operator_params, array('sql_query_template' => $sql_insert_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_insert_operator, $operator_params))), $current_user_id); /* NEW: Логируем SQL */
                                } else {
                                    $error_info = $stmt_insert_operator->errorInfo();
                                    error_log("OPERATOR_INSERT_FAILED_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql_insert_operator, $operator_params)); /* NEW: Логирование SQL ошибки */
                                    logKontragentActivity($dbh, $edit_id, 'OPERATOR_INSERT_FAILED_EXECUTE', array_merge($operator_params, array('error_info' => $error_info, 'sql_query_template' => $sql_insert_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_insert_operator, $operator_params))), $current_user_id); /* NEW: Логируем SQL */
                                    $response['success'] = false;
                                    $response['message'] .= ' Ошибка при добавлении данных оператора: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL');
                                }
                            }
                        } else { /* Если НЕ помечен как оператор */
                            if ($operator_exists) { /* Удаляем запись оператора, если она существует */
                                $sql_delete_operator = "DELETE FROM work.aa_kontragent_operator WHERE id_kontragent = :id_kontragent";
                                $stmt_delete_operator = $dbh->prepare($sql_delete_operator);
                                $delete_params = array(':id_kontragent' => $edit_id);
                                if ($stmt_delete_operator->execute($delete_params)) {
                                    logKontragentActivity($dbh, $edit_id, 'OPERATOR_DELETE_SUCCESS', array('sql_query_template' => $sql_delete_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_delete_operator, $delete_params)), $current_user_id); /* NEW: Логируем SQL */
                                } else {
                                    $error_info = $stmt_delete_operator->errorInfo();
                                    error_log("OPERATOR_DELETE_FAILED_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql_delete_operator, $delete_params)); /* NEW: Логирование SQL ошибки */                                    logKontragentActivity($dbh, $edit_id, 'OPERATOR_DELETE_FAILED_EXECUTE', array_merge($delete_params, array('error_info' => $error_info, 'sql_query_template' => $sql_delete_operator, 'sql_query_full' => build_full_sql_query($dbh, $sql_delete_operator, $delete_params))), $current_user_id); /* NEW: Логируем SQL */
                                    $response['success'] = false;
                                    $response['message'] .= ' Ошибка при удалении данных оператора: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL');
                                }
                            }
                        }
                    }
                } else {
                    $error_info = $stmt_update_main->errorInfo();
                    error_log("SAVE_FAILED_UPDATE_MAIN_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql_update_main, $params_update_main)); /* NEW: Логирование SQL ошибки */
                    $response['success'] = false;
                    $response['message'] = 'Ошибка при обновлении основных данных контрагента (execute failed): ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL');
                    logKontragentActivity($dbh, $edit_id, 'SAVE_FAILED_UPDATE_MAIN_EXECUTE', array_merge($data, array('error_info' => $error_info, 'sql_query_template' => $sql_update_main, 'sql_query_full' => build_full_sql_query($dbh, $sql_update_main, $params_update_main))), $current_user_id); /* NEW: Логируем SQL */
                }
            }
            if ($response['success']) {
                $dbh->commit();
            } else {
                $dbh->rollBack();
            }
        } catch (PDOException $e) {
            $dbh->rollBack();
            $response['success'] = false;
            $response['message'] = 'Ошибка БД при сохранении контрагента: ' . $e->getMessage();
            error_log("SAVE_FAILED_TRANSACTION DB Error: " . $e->getMessage()); /* NEW: Логирование SQL ошибки */
            logKontragentActivity($dbh, $edit_id, 'SAVE_FAILED_TRANSACTION', array('error' => $e->getMessage()), $current_user_id);
        }
        return $response;
    }
}

if (!function_exists('handleGetContracts')) {
    /*
     * Обрабатывает запрос на получение списка договоров для конкретного контрагента и компании.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleGetContracts($dbh, $current_user_id, $input_data) {
        $kontragent_id = isset($input_data['kontragent_id']) ? intval($input_data['kontragent_id']) : 0;
        $company_id = isset($input_data['company_id']) ? intval($input_data['company_id']) : 0;

        if ($kontragent_id == 0 || $company_id == 0) {
            return array('success' => false, 'message' => 'Не указан ID контрагента или компании для загрузки договоров.');
        }

        $sql = "SELECT id, num, dtfrom, year FROM work.aa_list_dog_agent2company WHERE id_kontragent = :id_kontragent AND id_company = :id_company ORDER BY timecreate DESC"; /* NEW: SQL для логирования */
        $params = array(':id_kontragent' => $kontragent_id, ':id_company' => $company_id);
        $log_data_base = array(
            'company_id' => $company_id,
            'sql_query_template' => $sql, /* NEW: Шаблон SQL-запроса */
            'sql_query_full' => build_full_sql_query($dbh, $sql, $params) /* NEW: Полный SQL-запрос */
        );
        try {
            $stmt = $dbh->prepare($sql);
            $stmt->execute($params);
            $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /* Форматируем даты для DevExtreme DataGrid */
            foreach ($contracts as &$contract) {
                if (!empty($contract['dtfrom'])) {
                    $contract['dtfrom'] = date('Y-m-d', strtotime($contract['dtfrom']));
                }
            }
            unset($contract); /* Обязательно снимаем ссылку */
            logKontragentActivity($dbh, $kontragent_id, 'GET_CONTRACTS', array_merge($log_data_base, array('count' => count($contracts))), $current_user_id); /* NEW: Передаем SQL */
            return array('success' => true, 'data' => $contracts);        } catch (PDOException $e) {
            error_log("GET_CONTRACTS_FAILED DB Error: " . $e->getMessage()); /* NEW: Логирование SQL ошибки */
            logKontragentActivity($dbh, $kontragent_id, 'GET_CONTRACTS_FAILED', array_merge($log_data_base, array('error' => $e->getMessage())), $current_user_id); /* NEW: Передаем SQL */
            return array('success' => false, 'message' => 'Ошибка при загрузке договоров: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleSaveContract')) {
    /*
     * Обрабатывает запрос на сохранение (добавление или обновление) договора.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleSaveContract($dbh, $current_user_id, $input_data) {
        $data = $input_data;
        $kontragent_id = isset($data['id_kontragent']) ? intval($data['id_kontragent']) : 0;
        $contract_id = isset($data['id']) ? intval($data['id']) : 0; /* ID договора, если это обновление */
        $company_id = isset($data['id_company']) ? intval($data['id_company']) : 0;
        $num = isset($data['num']) ? $data['num'] : '';
        $dtfrom = isset($data['dtfrom']) && !empty($data['dtfrom']) ? date('Y-m-d', strtotime($data['dtfrom'])) : null;
        $year = isset($data['year']) ? intval($data['year']) : null;

        if ($kontragent_id == 0 || $company_id == 0) {
            return array('success' => false, 'message' => 'Не указан ID контрагента или компании для договора.');
        }
        try {
            $sql = ''; /* Объявляем $sql здесь, чтобы он был доступен для логирования */
            $params = array(); /* Объявляем $params здесь */
            if ($contract_id == 0) { /* Добавление нового договора */
                /* NEW: Генерация номера договора (пример, можно настроить по логике бизнеса) */
                $current_year = date('Y');
                $sql_get_max_num = "SELECT MAX(CAST(num AS UNSIGNED)) FROM work.aa_list_dog_agent2company WHERE id_company = :id_company AND year = :year";
                $stmt_get_max_num = $dbh->prepare($sql_get_max_num);
                $stmt_get_max_num->execute(array(':id_company' => $company_id, ':year' => $year));
                $max_num = $stmt_get_max_num->fetchColumn();
                $new_num = ($max_num ? $max_num + 1 : 1);
                $num = strval($new_num); // Используем сгенерированный номер

                $sql = "INSERT INTO work.aa_list_dog_agent2company (id_kontragent, id_company, num, dtfrom, year) VALUES (:id_kontragent, :id_company, :num, :dtfrom, :year)";
                $params = array(
                    ':id_kontragent' => $kontragent_id,
                    ':id_company' => $company_id,
                    ':num' => $num,
                    ':dtfrom' => $dtfrom,
                    ':year' => $year
                );
                $stmt = $dbh->prepare($sql);
                $success = $stmt->execute($params);
                $new_id = $dbh->lastInsertId();
                $message = 'Договор успешно добавлен (Номер: ' . $num . ').';
                $log_action = 'CONTRACT_INSERT_SUCCESS';            } else { /* Обновление существующего договора */
                $sql = "UPDATE work.aa_list_dog_agent2company SET num = :num, dtfrom = :dtfrom, year = :year WHERE id = :id AND id_kontragent = :id_kontragent AND id_company = :id_company";
                $params = array(
                    ':num' => $num, /* При обновлении номер может быть передан или оставлен как есть */
                    ':dtfrom' => $dtfrom,
                    ':year' => $year,
                    ':id' => $contract_id,
                    ':id_kontragent' => $kontragent_id,
                    ':id_company' => $company_id
                );
                $stmt = $dbh->prepare($sql);
                $success = $stmt->execute($params);
                $new_id = $contract_id;
                $message = 'Договор успешно обновлен.';
                $log_action = 'CONTRACT_UPDATE_SUCCESS';
            }
            if ($success) {
                logKontragentActivity($dbh, $kontragent_id, $log_action, array_merge($data, array('sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params))), $current_user_id); /* NEW: Логируем SQL */
                return array('success' => true, 'message' => $message, 'new_id' => $new_id);
            } else {
                $error_info = $stmt->errorInfo();
                error_log("CONTRACT_SAVE_FAILED_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql, $params)); /* NEW: Логирование SQL ошибки */
                logKontragentActivity($dbh, $kontragent_id, 'CONTRACT_SAVE_FAILED_EXECUTE', array_merge($data, array('error_info' => $error_info, 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params))), $current_user_id); /* NEW: Логируем SQL */
                return array('success' => false, 'message' => 'Ошибка при сохранении договора: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL'));
            }
        } catch (PDOException $e) {
            error_log("CONTRACT_SAVE_FAILED_DB_ERROR DB Error: " . $e->getMessage()); /* NEW: Логирование SQL ошибки */
            logKontragentActivity($dbh, $kontragent_id, 'CONTRACT_SAVE_FAILED_DB_ERROR', array_merge($data, array('error' => $e->getMessage(), 'sql_query_template' => (isset($sql) ? $sql : 'N/A'), 'sql_query_full' => (isset($sql) ? build_full_sql_query($dbh, $sql, $params) : 'N/A'))), $current_user_id); /* NEW: Логируем SQL */
            return array('success' => false, 'message' => 'Ошибка БД при сохранении договора: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleDeleteContract')) {
    /*
     * Обрабатывает запрос на удаление договора.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleDeleteContract($dbh, $current_user_id, $input_data) {
        $contract_id = isset($input_data['id']) ? intval($input_data['id']) : 0;
        $kontragent_id = isset($input_data['id_kontragent']) ? intval($input_data['id_kontragent']) : 0;

        if ($contract_id == 0 || $kontragent_id == 0) {
            return array('success' => false, 'message' => 'Не указан ID договора или контрагента для удаления.');
        }

        $sql = "DELETE FROM work.aa_list_dog_agent2company WHERE id = :id AND id_kontragent = :id_kontragent"; /* NEW: SQL для логирования */
        $params = array(':id' => $contract_id, ':id_kontragent' => $kontragent_id);
        try {
            $stmt = $dbh->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                logKontragentActivity($dbh, $kontragent_id, 'CONTRACT_DELETE_SUCCESS', array('contract_id' => $contract_id, 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */
                return array('success' => true, 'message' => 'Договор успешно удален.');
            } else {
                $error_info = $stmt->errorInfo();
                error_log("CONTRACT_DELETE_FAILED_EXECUTE SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql, $params)); /* NEW: Логирование SQL ошибки */
                logKontragentActivity($dbh, $kontragent_id, 'CONTRACT_DELETE_FAILED_EXECUTE', array('contract_id' => $contract_id, 'error_info' => $error_info, 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */
                return array('success' => false, 'message' => 'Ошибка при удалении договора: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL'));
            }
        } catch (PDOException $e) {
            error_log("CONTRACT_DELETE_FAILED_DB_ERROR DB Error: " . $e->getMessage()); /* NEW: Логирование SQL ошибки */
            logKontragentActivity($dbh, $kontragent_id, 'CONTRACT_DELETE_DB_ERROR', array('contract_id' => $contract_id, 'error' => $e->getMessage(), 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */
            return array('success' => false, 'message' => 'Ошибка БД при удалении договора: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleGetRegions')) {
    /*
     * NEW: Функция для получения списка регионов из таблицы aa_region.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @return array Массив данных для JSON ответа.
     */
    function handleGetRegions($dbh) {
        $sql = "SELECT id, name FROM work.aa_region ORDER BY name ASC"; /* NEW: SQL для логирования */
        $params = array(); /* Нет параметров для этого запроса */
        try {
            $stmt = $dbh->prepare($sql);
            $stmt->execute($params);
            $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array('success' => true, 'data' => $regions);
        } catch (PDOException $e) {
            error_log("Ошибка при загрузке регионов: " . $e->getMessage());
            return array('success' => false, 'message' => 'Ошибка при загрузке регионов: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleGetCitiesByRegion')) {
    /*
     * NEW: Функция для получения списка городов из таблицы aa_city по ID региона.
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleGetCitiesByRegion($dbh, $input_data) {        $region_id = isset($input_data['id_region']) ? intval($input_data['id_region']) : 0;
        if ($region_id == 0) {
            return array('success' => true, 'data' => array()); /* Возвращаем пустой массив, если ID региона не указан */
        }
        $sql = "SELECT id, name FROM work.aa_city WHERE id_region = :id_region ORDER BY name ASC"; /* NEW: SQL для логирования */
        $params = array(':id_region' => $region_id);
        try {
            $stmt = $dbh->prepare($sql);
            $stmt->execute($params);
            $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array('success' => true, 'data' => $cities);        } catch (PDOException $e) {
            error_log("Ошибка при загрузке городов: " . $e->getMessage());
            return array('success' => false, 'message' => 'Ошибка при загрузке городов: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleMarkKontragentDeleted')) {
    /*
     * NEW: Функция для мягкого удаления контрагента (установка is_delete = 1).
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleMarkKontragentDeleted($dbh, $current_user_id, $input_data) {
        $kontragent_id = isset($input_data['id']) ? intval($input_data['id']) : 0;
        if ($kontragent_id == 0) {
            return array('success' => false, 'message' => 'Не указан ID контрагента для удаления.');
        }
        $sql = "UPDATE work.aa_kontragent SET is_delete = 1, id_user_last_update = :id_user_last_update WHERE id = :id"; /* NEW: SQL для логирования */
        $params = array(':id' => $kontragent_id, ':id_user_last_update' => $current_user_id);
        try {
            $stmt = $dbh->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                logKontragentActivity($dbh, $kontragent_id, 'SOFT_DELETE_SUCCESS', array('sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */
                return array('success' => true, 'message' => 'Контрагент успешно помечен как удаленный.');
            } else {
                $error_info = $stmt->errorInfo();
                error_log("SOFT_DELETE_FAILED SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql, $params)); /* NEW: Логирование SQL ошибки */
                logKontragentActivity($dbh, $kontragent_id, 'SOFT_DELETE_FAILED', array('error_info' => $error_info, 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */                return array('success' => false, 'message' => 'Ошибка при пометке контрагента как удаленного: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL'));
            }
        } catch (PDOException $e) {
            error_log("SOFT_DELETE_DB_ERROR DB Error: " . $e->getMessage()); /* NEW: Логирование SQL ошибки */
            logKontragentActivity($dbh, $kontragent_id, 'SOFT_DELETE_DB_ERROR', array('error' => $e->getMessage(), 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */
            return array('success' => false, 'message' => 'Ошибка БД при пометке контрагента как удаленного: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleRestoreKontragent')) {
    /*
     * NEW: Функция для восстановления контрагента (установка is_delete = 0).
     * @param PDO $dbh Объект PDO подключения к базе данных.
     * @param int $current_user_id ID текущего пользователя.
     * @param array $input_data Входные данные (GET/POST/JSON).
     * @return array Массив данных для JSON ответа.
     */
    function handleRestoreKontragent($dbh, $current_user_id, $input_data) {
        $kontragent_id = isset($input_data['id']) ? intval($input_data['id']) : 0;
        if ($kontragent_id == 0) {
            return array('success' => false, 'message' => 'Не указан ID контрагента для восстановления.');
        }

        $sql = "UPDATE work.aa_kontragent SET is_delete = 0, id_user_last_update = :id_user_last_update WHERE id = :id"; /* NEW: SQL для логирования */
        $params = array(':id' => $kontragent_id, ':id_user_last_update' => $current_user_id);
        try {
            $stmt = $dbh->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                logKontragentActivity($dbh, $kontragent_id, 'RESTORE_SUCCESS', array('sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */                return array('success' => true, 'message' => 'Контрагент успешно восстановлен.');
            } else {
                $error_info = $stmt->errorInfo();
                error_log("RESTORE_FAILED SQL Error: " . (isset($error_info[2]) ? $error_info[2] : 'Unknown SQL error') . " for query: " . build_full_sql_query($dbh, $sql, $params)); /* NEW: Логирование SQL ошибки */
                logKontragentActivity($dbh, $kontragent_id, 'RESTORE_FAILED', array('error_info' => $error_info, 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */
                return array('success' => false, 'message' => 'Ошибка при восстановлении контрагента: ' . (isset($error_info[2]) ? $error_info[2] : 'Неизвестная ошибка SQL'));
            }
        } catch (PDOException $e) {
            error_log("RESTORE_DB_ERROR DB Error: " . $e->getMessage()); /* NEW: Логирование SQL ошибки */
            logKontragentActivity($dbh, $kontragent_id, 'RESTORE_DB_ERROR', array('error' => $e->getMessage(), 'sql_query_template' => $sql, 'sql_query_full' => build_full_sql_query($dbh, $sql, $params)), $current_user_id); /* NEW: Логируем SQL */
            return array('success' => false, 'message' => 'Ошибка БД при восстановлении контрагента: ' . $e->getMessage());
        }
    }
}

if (!function_exists('handleLogClientNotify')) {
    /* NEW: Функция для логирования уведомлений из клиентской части. */
    function handleLogClientNotify($dbh, $user_id, $input_data) {
        $message = isset($input_data['message']) ? $input_data['message'] : 'No message';
        $type = isset($input_data['type']) ? $input_data['type'] : 'info';
        $kontragent_id = isset($input_data['kontragent_id']) ? intval($input_data['kontragent_id']) : 0;
        $url = isset($input_data['url']) ? $input_data['url'] : 'N/A';
        $log_data = array(
            'client_message' => $message,
            'client_type' => $type,
            'client_url' => $url
        );

        logKontragentActivity($dbh, $kontragent_id, 'CLIENT_NOTIFY', $log_data, $user_id);
        return array('success' => true); /* Всегда возвращаем успех, чтобы не создавать каскад ошибок */
    }
}


/* Основной блок выполнения для веб-запросов */
if (!defined('PHPUNIT_TEST_ENVIRONMENT')) {
    $current_user_id = getCurrentUserId();

    /* ИСПРАВЛЕНО: Унифицированный способ получения входных данных */
    $input_data = $_REQUEST; /* Объединяет GET и POST */
    if (empty($input_data) && (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
        /* Если $_REQUEST пуст и тип контента JSON, пытаемся парсить сырой ввод */
        $raw_input = file_get_contents('php://input');
        if ($raw_input) {
            $json_data = json_decode($raw_input, true);            if ($json_data !== null) {
                $input_data = $json_data;
            }
        }
    }

    /* Устанавливаем заголовок для JSON ответа */
    header('Content-Type: application/json; charset=utf-8');

    /* Обрабатываем запрос и выводим результат */
    echo json_encode(processAjaxKontragentRequest($dbh, $current_user_id, $input_data));
    exit;
}
