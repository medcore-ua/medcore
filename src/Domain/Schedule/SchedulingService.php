<?php

/*
 *
 *                      _
 *   _ __ ___   ___  __| | ___ ___  _ __ ___       _   _  __ _
 *  | '_ ` _ \ / _ \/ _` |/ __/ _ \| '__/ _ \_____| | | |/ _` |
 *  | | | | | |  __/ (_| | (_| (_) | | |  __/_____| |_| | (_| |
 *  |_| |_| |_|\___|\__,_|\___\___/|_|  \___|      \__,_|\__,_|
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author MedCore Ukraine
 * @link https://medcore.pp.ua/
 *
 *
 */

namespace App\Domain\Schedule;

use App\Domain\Appointment\AppointmentRepository;
use App\Domain\Billing\ServiceRepository;
use App\Domain\Room\RoomRepository;
// Assuming this exists or will be created
use DateInterval;
use DateTime;

class SchedulingService
{
    private DoctorScheduleRepository $doctorScheduleRepository;
    private ScheduleExceptionRepository $scheduleExceptionRepository;
    private AppointmentRepository $appointmentRepository;
    private ServiceRepository $serviceRepository;
    private RoomRepository $roomRepository;

    public function __construct(
        DoctorScheduleRepository $doctorScheduleRepository,
        ScheduleExceptionRepository $scheduleExceptionRepository,
        AppointmentRepository $appointmentRepository,
        ServiceRepository $serviceRepository,
        RoomRepository $roomRepository
    ) {
        $this->doctorScheduleRepository = $doctorScheduleRepository;
        $this->scheduleExceptionRepository = $scheduleExceptionRepository;
        $this->appointmentRepository = $appointmentRepository;
        $this->serviceRepository = $serviceRepository;
        $this->roomRepository = $roomRepository;
    }

    /**
     * Generates time slots for a given doctor on a specific date.
     *
     * @param  int      $doctorId
     * @param  DateTime $date
     * @param  int      $serviceId Optional, to get specific duration. Default to 30 mins if not provided.
     * @return array    An array of time slots with availability info ['time' => DateTime, 'available' => bool, 'booked' => bool].
     */
    public function getAvailableTimeSlots(int $doctorId, DateTime $date, ?int $serviceId = null) : array
    {
        $availableSlots = [];
        $dayOfWeek = (int)$date->format('w'); // 0 for Sunday, 6 for Saturday

        // 1. Get default schedule for the day
        $defaultSchedule = $this->doctorScheduleRepository->findByDoctorAndDay($doctorId, $dayOfWeek);

        if (! $defaultSchedule || ! $defaultSchedule['is_available']) {
            return []; // Doctor is not available on this day by default
        }

        $scheduleStartTime = new DateTime($date->format('Y-m-d') . ' ' . $defaultSchedule['start_time']);
        $scheduleEndTime = new DateTime($date->format('Y-m-d') . ' ' . $defaultSchedule['end_time']);

        // Determine slot duration
        $slotDurationMinutes = 30; // Default
        if (null !== $serviceId) {
            $service = $this->serviceRepository->findById($serviceId);
            if ($service && isset($service['duration_minutes'])) {
                $slotDurationMinutes = (int)$service['duration_minutes'];
            }
        }
        $slotInterval = new DateInterval('PT' . $slotDurationMinutes . 'M');

        // 2. Adjust schedule based on exceptions
        $exceptions = $this->scheduleExceptionRepository->findByDoctorAndDate($doctorId, $date->format('Y-m-d'));

        $workingPeriods = [[$scheduleStartTime, $scheduleEndTime]]; // Start with default working period

        foreach ($exceptions as $exception) {
            $exceptionStartTime = new DateTime($date->format('Y-m-d') . ' ' . $exception['start_time']);
            $exceptionEndTime = new DateTime($date->format('Y-m-d') . ' ' . $exception['end_time']);

            if (! $exception['is_available']) {
                // If exception is unavailable, remove this time from working periods
                $newWorkingPeriods = [];
                foreach ($workingPeriods as $period) {
                    // Check for overlap and split/remove periods
                    // This is a simplified overlap detection. A more robust solution would be needed for complex overlaps.
                    if ($period[0] < $exceptionEndTime && $period[1] > $exceptionStartTime) {
                        // Overlap exists
                        if ($period[0] < $exceptionStartTime) {
                            $newWorkingPeriods[] = [$period[0], $exceptionStartTime];
                        }
                        if ($period[1] > $exceptionEndTime) {
                            $newWorkingPeriods[] = [$exceptionEndTime, $period[1]];
                        }
                    } else {
                        $newWorkingPeriods[] = $period;
                    }
                }
                $workingPeriods = $newWorkingPeriods;
            } else {
                // If exception makes time available, add it (this is more complex, might need merging periods)
                // For now, let's assume is_available exceptions fill gaps or extend existing periods.
                // This logic might need refinement based on exact requirements.
                // For simplicity, this example only handles 'unavailable' exceptions correctly.
            }
        }

        // 3. Generate slots from working periods
        $allSlots = [];
        foreach ($workingPeriods as $period) {
            $currentSlotStart = $period[0];
            $periodEnd = $period[1];

            while ($currentSlotStart < $periodEnd) {
                $slotEnd = clone $currentSlotStart;
                $slotEnd->add($slotInterval);

                if ($slotEnd <= $periodEnd) {
                    $allSlots[] = $currentSlotStart;
                }
                $currentSlotStart = $slotEnd;
            }
        }

        // 4. Check if slots are in the past
        $now = new DateTime();

        // 5. Check which slots conflict with existing appointments
        // This findByDoctorAndDate method might not exist in the AppointmentRepository yet.
        // I will assume it returns appointments for the given doctor and date.
        $bookedAppointments = $this->appointmentRepository->findByDoctorIdAndDateRange(
            $doctorId,
            $date->format('Y-m-d 00:00:00'),
            $date->format('Y-m-d 23:59:59')
        );

        $finalSlots = [];
        foreach ($allSlots as $slot) {
            $isInPast = $slot < $now;

            $isBooked = false;
            foreach ($bookedAppointments as $appointment) {
                $appointmentStart = new DateTime($appointment['start_time']);
                $appointmentEnd = new DateTime($appointment['end_time']);
                $slotEnd = clone $slot;
                $slotEnd->add($slotInterval);

                // Check for overlap: [slot, slotEnd) overlaps with [appointmentStart, appointmentEnd)
                if ($slot < $appointmentEnd && $slotEnd > $appointmentStart) {
                    $isBooked = true;
                    break;
                }
            }

            $isAvailable = ! $isInPast && ! $isBooked;

            $finalSlots[] = [
                'time' => $slot,
                'available' => $isAvailable,
                'is_in_past' => $isInPast,
                'is_booked' => $isBooked
            ];
        }

        return $finalSlots;
    }

    /**
     * Check if a room is available for given time slot
     */
    public function isRoomAvailable(?int $roomId, DateTime $startTime, DateTime $endTime, ?int $excludeAppointmentId = null) : bool
    {
        if (! $roomId) {
            return true; // No room specified, always available
        }

        // Check if room exists and is available
        $room = $this->roomRepository->findById($roomId);
        if (! $room || ! $room['is_available']) {
            return false;
        }

        // Get existing appointments for the room in the time slot
        $conflictingAppointments = $this->appointmentRepository->findByRoomIdAndDateRange(
            $roomId,
            $startTime->format('Y-m-d H:i:s'),
            $endTime->format('Y-m-d H:i:s')
        );

        // Filter out the appointment we're excluding (for editing)
        if ($excludeAppointmentId) {
            $conflictingAppointments = array_filter($conflictingAppointments, function ($appointment) use ($excludeAppointmentId) {
                return (int)$appointment['id'] !== $excludeAppointmentId;
            });
        }

        // Check if any conflicting appointments are not cancelled
        foreach ($conflictingAppointments as $appointment) {
            if (! in_array($appointment['status'], ['cancelled', 'no-show'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a time slot is available for both doctor and room
     */
    public function isTimeSlotAvailable(int $doctorId, ?int $roomId, DateTime $startTime, DateTime $endTime, ?int $excludeAppointmentId = null) : array
    {
        $result = [
            'available' => true,
            'conflicts' => []
        ];

        // Check doctor availability (existing logic from getAvailableTimeSlots)
        $doctorAppointments = $this->appointmentRepository->findByDoctorIdAndDateRange(
            $doctorId,
            $startTime->format('Y-m-d 00:00:00'),
            $startTime->format('Y-m-d 23:59:59')
        );

        foreach ($doctorAppointments as $appointment) {
            if ($excludeAppointmentId && (int)$appointment['id'] === $excludeAppointmentId) {
                continue;
            }

            $appointmentStart = new DateTime($appointment['start_time']);
            $appointmentEnd = new DateTime($appointment['end_time']);

            if ($startTime < $appointmentEnd && $endTime > $appointmentStart) {
                $result['available'] = false;
                $result['conflicts'][] = [
                    'type' => 'doctor',
                    'message' => 'Лікар зайнятий в цей час',
                    'appointment_id' => $appointment['id']
                ];
                break;
            }
        }

        // Check room availability
        if ($roomId && ! $this->isRoomAvailable($roomId, $startTime, $endTime, $excludeAppointmentId)) {
            $result['available'] = false;
            $result['conflicts'][] = [
                'type' => 'room',
                'message' => 'Кімната зайнята в цей час',
                'room_id' => $roomId
            ];
        }

        return $result;
    }

    /**
     * Get available rooms for a given time slot
     */
    public function getAvailableRooms(DateTime $startTime, DateTime $endTime, ?int $excludeAppointmentId = null) : array
    {
        $allRooms = $this->roomRepository->findAvailable();
        $availableRooms = [];

        foreach ($allRooms as $room) {
            if ($this->isRoomAvailable($room['id'], $startTime, $endTime, $excludeAppointmentId)) {
                $availableRooms[] = $room;
            }
        }

        return $availableRooms;
    }

    /**
     * Validate appointment booking with room conflict detection
     */
    public function validateAppointmentBooking(array $data) : array
    {
        $result = [
            'valid' => true,
            'errors' => []
        ];

        $startTime = new DateTime($data['start_time']);
        $endTime = new DateTime($data['end_time']);
        $doctorId = (int)$data['doctor_id'];
        $roomId = isset($data['room_id']) ? (int)$data['room_id'] : null;
        $excludeAppointmentId = $data['exclude_id'] ?? null;

        // Check if room is available (if specified)
        if ($roomId && ! $this->isRoomAvailable($roomId, $startTime, $endTime, $excludeAppointmentId)) {
            $result['valid'] = false;
            $result['errors'][] = [
                'field' => 'room_id',
                'message' => 'Обрана кімната зайнята в цей час'
            ];
        }

        // Check doctor availability
        $doctorAvailability = $this->isTimeSlotAvailable($doctorId, $roomId, $startTime, $endTime, $excludeAppointmentId);
        if (! $doctorAvailability['available']) {
            $result['valid'] = false;
            foreach ($doctorAvailability['conflicts'] as $conflict) {
                $result['errors'][] = [
                    'field' => 'start_time',
                    'message' => $conflict['message']
                ];
            }
        }

        return $result;
    }

    // You might also add methods like:
    // public function bookAppointment(int $doctorId, int $patientId, DateTime $startTime, int $serviceId): bool;
    // public function cancelAppointment(int $appointmentId): bool;
}
