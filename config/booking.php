<?php

declare(strict_types=1);

/**
 * Matriz de configuração operacional e catálogo de serviços.
 * Centraliza as regras de negócio, limites de agendamento e mapeamento para o Google Calendar.
 */
return [
    // Fuso horário canônico da operação
    'timezone' => getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo',

    // Canal de continuidade operacional via mensageria
    'whatsapp' => getenv('BUSINESS_WHATSAPP') ?: '5511999999999',

    // Janelas e restrições temporais de agendamento
    'minimumNoticeMinutes' => 120, // Bloqueia agendamentos sem antecedência operacional mínima
    'bookingHorizonDays'   => 30,  // Limite superior da janela de agendamento futuro
    'bookingGapMinutes'    => 0,   // Intervalo de segurança obrigatório entre atendimentos
    'slotIntervalMinutes'  => 40,  // Granularidade para geração de slots disponíveis
    'maxAddOns'            => 3,   // Limite de opcionais por atendimento

    // Grade de funcionamento semanal (1 = Segunda-feira ... 7 = Domingo)
    'openingHours' => [
        1 => [['09:00', '12:00'], ['13:00', '18:00']],
        2 => [['09:00', '12:00'], ['13:00', '18:00']],
        3 => [['09:00', '12:00'], ['13:00', '18:00']],
        4 => [['09:00', '12:00'], ['13:00', '18:00']],
        5 => [['09:00', '12:00'], ['13:00', '18:00']],
        6 => [['09:00', '14:00']],
    ],

    // Catálogo canônico de serviços principais (valores em centavos para precisão monetária)
    'services' => [
        [
            'id' => 'service-standard',
            'name' => 'Atendimento Padrão',
            'durationMinutes' => 40,
            'priceFromCents' => 5000,
        ],
        [
            'id' => 'service-express',
            'name' => 'Atendimento Expresso',
            'durationMinutes' => 20,
            'priceFromCents' => 2500,
        ],
        [
            'id' => 'service-complete',
            'name' => 'Atendimento Completo',
            'durationMinutes' => 90,
            'priceFromCents' => 9000,
        ],
    ],

    // Serviços adicionais permitidos
    'addOns' => [
        [
            'id' => 'addon-special',
            'name' => 'Procedimento Adicional',
            'priceIncrementCents' => 2000,
        ],
        [
            'id' => 'addon-premium',
            'name' => 'Finalização Especial',
            'priceIncrementCents' => 1500,
        ],
    ],

    // Metadados visuais para representação nos calendários do Google (colorId 1..11)
    'calendarPresentation' => [
        'services' => [
            'service-standard' => ['colorId' => '7', 'icon' => '⭐'],
            'service-express'  => ['colorId' => '5', 'icon' => '⚡'],
            'service-complete' => ['colorId' => '9', 'icon' => '💎'],
        ],
    ],
];
