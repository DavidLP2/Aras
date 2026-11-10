ALTER TABLE `Usuarios`
    ADD COLUMN `permissions` TEXT NULL AFTER `role`,
    ADD COLUMN `superadmin_slot` TINYINT GENERATED ALWAYS AS (
        CASE WHEN `role` = 'Superadministrativo' THEN 1 ELSE NULL END
    ) STORED,
    ADD UNIQUE KEY `uq_usuarios_single_superadmin` (`superadmin_slot`);
