<?php

namespace App\Enums;

/**
 * Роли учётных записей (ТЗ 2.8). Самостоятельная регистрация запрещена,
 * аккаунты создаются по нисходящей иерархии: admin -> municipal_coordinator -> school_operator.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case SuperCoordinator = 'super_coordinator';
    case KazanSubjectCoordinator = 'kazan_subject_coordinator';
    case RocRepresentative = 'roc_representative';
    case RocSubjectCoordinator = 'roc_subject_coordinator';
    case MunicipalCoordinator = 'municipal_coordinator';
    case CommissionChair = 'commission_chair';
    case SchoolOperator = 'school_operator';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::SuperCoordinator => 'Супер-координатор Казани',
            self::KazanSubjectCoordinator => 'Координатор Казани по предмету',
            self::RocRepresentative => 'Представитель РОЦ РТ',
            self::RocSubjectCoordinator => 'Координатор РОЦ по предмету',
            self::MunicipalCoordinator => 'Муниципальный координатор АТЕ',
            self::CommissionChair => 'Председатель комиссии МЭ',
            self::SchoolOperator => 'Школьный оператор',
        };
    }
}
