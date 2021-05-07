<?php

namespace e10pro\booking;


/**
 * Class ModuleServices
 * @package e10pro\booking
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function anonymizeBooking()
	{
		$q [] = 'SELECT * FROM [e10pro_booking_bookings]';

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$ln = $this->app->faker->lastName;

			if (mb_substr($ln, -1, 1, 'UTF-8') === 'á')
				$fn = $this->app->faker->firstNameFemale;
			else
				$fn = $this->app->faker->firstNameMale;

			$fullName = $ln . ' ' . $fn;

			$this->app->db()->query ('UPDATE [e10pro_booking_bookings] SET subject = %s', $fullName, ' WHERE ndx = ', $r['ndx']);
		}
	}

	public function onAnonymize ()
	{
		$this->anonymizeBooking();
	}
}
