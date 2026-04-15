<?php

function bdta_appointment_type_can_show_in_public_services(int $is_group_class, int $is_mini_session): bool
{
    return $is_group_class !== 1 && $is_mini_session !== 1;
}

function bdta_normalize_appointment_type_public_available(
    int $requested_public_available,
    int $is_group_class,
    int $is_mini_session
): int {
    return $requested_public_available === 1
        && bdta_appointment_type_can_show_in_public_services($is_group_class, $is_mini_session)
        ? 1
        : 0;
}
