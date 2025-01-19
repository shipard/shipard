<?php

namespace Shipard\Utils;

# SOURCE: http://astro.mff.cuni.cz/mira/sh/sh.php?type=trans2 and http://astro.mff.cuni.cz/mira/sh/db_trans.tar.gz
# db_trans.php - WGS-84 to S-JTSK and S-42 transformation.
# Miroslav Broz (miroslav.broz@email.cz), Oct 11th 2005

# References:
#
# Hrdina, Z.: Transformace souradnic ze systemu WGS-84
# do systemu S-JTSK. Praha: CVUT, 1997.
# http://www.geospeleos.com/Mapovani/WGS84toSJTSK/WGS84toSJTSK.htm
# http://www.geospeleos.com/Mapovani/WGS84toSJTSK/WGS_JTSK.pdf
#
# Hrdina, Z.: Prepocet z S-JTSK do WGS-84. 2002.
# http://gpsweb.cz/JTSK-WGS.htm.
#
# Converting between grid eastings and northings
# and ellipsoidal latititude and longitude.
# http://www.gps.gov.uk/guidec.asp

########################################################################

# A useful table to remember:

# coordinate system   WGS-84      S-1942 (or S42)    S-JSTK
# ellipsoid           WGS-84      Krasovskij         Bessel
# projection          Mercator    Mercator           Krovak

########################################################################

# numbers from: Garmin eTrex Legend C - User Ellipsoid
# DX = -23
# DY = +124
# DZ = +84
# DA = -108 m (a difference between semimajor axes of the WGS and Krasovskij ellipsoids)
# DF = 0.00480760 (zmena zplosteni?)

########################################################################

// alternative implementation: https://github.com/josefzamrzla/JTSK_Converter/blob/master/Converter.php:


/**
 * class Wgs84
 */
class Wgs84
{
  static $pi = 3.1415926535;
  static function sqr($x) { return ($x*$x); }

  ########################################################################

  # TRANSLATION AND INFINETESIMAL ROTATION OF THE CARTESIAN COORDINATES
  # FOR THE THREE ELLIPSOIDS (WGS-84, S-JTSK, S-1942)

  ########################################################################

  static function Helmert_transformation($xs, $ys, $zs, $dx, $dy, $dz, $wx, $wy, $wz, $m)
  {
    $xn = $dx + (1+$m)*($xs + $wz*$ys - $wy*$zs);
    $yn = $dy + (1+$m)*(-$wz*$xs + $ys + $wx*$zs);
    $zn = $dz + (1+$m)*($wy*$xs - $wx*$ys + $zs);

    return [$xn, $yn, $zn];
  }

  ########################################################################
  # Hrdina (1997)
  static function WGS84_SJTSK_transformation_xyz_xyz($xs, $ys, $zs)
  {
    # coefficients of the transformation WGS-84 -> S-JTSK
    $dx = -570.69; $dy = -85.69; $dz = -462.84;	# translation
    $arcsec = 1./3600.*self::$pi/180.;
    $wz = +5.2611*$arcsec; $wy = +1.58676*$arcsec; $wx = +4.99821*$arcsec;	# rotation
    $m = -3.543e-6;

    list($xn, $yn, $zn) = self::Helmert_transformation($xs, $ys, $zs, $dx, $dy, $dz, $wx, $wy, $wz, $m);

    return [$xn, $yn, $zn];
  }

  ########################################################################
  # Hrdina (2002)
  static function SJTSK_WGS84_transformation_xyz_xyz($xs, $ys, $zs)
  {
    # coefficients of the transformation WGS-84 -> S-JTSK
    $dx = +570.69; $dy = +85.69; $dz = +462.84;	# translation
    $arcsec = 1./3600.*self::$pi/180.;
    $wz = -5.2611*$arcsec; $wy = -1.58676*$arcsec; $wx = -4.99821*$arcsec;	# rotation
    $m = +3.543e-6;

    list($xn, $yn, $zn) = self::Helmert_transformation($xs, $ys, $zs, $dx, $dy, $dz, $wx, $wy, $wz, $m);

    return [$xn, $yn, $zn];
  }

  ########################################################################

  static function WGS84_S42_transformation_xyz_xyz($xs, $ys, $zs)
  {
    # coefficients of the transformation WGS-84 -> S-42
    $dx = -23; $dy = +124; $dz = +84;	# translation according to Garmin

    # this small translation is NOT in the Garmin?!
    $arcsec = 1./3600.*self::$pi/180.;
    $wx = -0.13*$arcsec; $wy = -0.25*$arcsec; $wz = +0.02*$arcsec;	# rotation
    $m = -1.1e-6;

    list($xn, $yn, $zn) = self::Helmert_transformation($xs, $ys, $zs, $dx, $dy, $dz, $wx, $wy, $wz, $m);

    return [$xn, $yn, $zn];
  }

  ########################################################################

  static function S42_WGS84_transformation_xyz_xyz($xs, $ys, $zs)
  {
    # pouze prehozena znamenka?!
    # coefficients of the transformation S-42 -> WGS-84
    $dx = +23; $dy = -124; $dz = -84;	# translation

    $arcsec = 1./3600.*self::$pi/180.;
    $wx = +0.13*$arcsec; $wy = +0.25*$arcsec; $wz = -0.02*$arcsec;	# rotation
    $m = +1.1e-6;

    list($xn, $yn, $zn) = self::Helmert_transformation($xs, $ys, $zs, $dx, $dy, $dz, $wx, $wy, $wz, $m);

    return [$xn, $yn, $zn];
  }


  ########################################################################
  # CONVERSION BETWEEN ELLIPSOIDAL LATITUDE, LONGITUDE, HEIGTH
  # AND CARTESIAN COORDINATES
  ########################################################################
  # Hrdina (1997)
  static function Ellipsoid_xyz_BLH($x, $y, $z, $a, $f_1)
  {
    $a_b = $f_1/($f_1-1.); $p = sqrt(self::sqr($x) + self::sqr($y)); $e2 = 1.-self::sqr(1.-1./$f_1);
    $theta = atan($z*$a_b/$p); $st = sin($theta); $ct = cos($theta);
    $t = ($z+$e2*$a_b*$a*self::sqr($st)*$st) / ($p-$e2*$a*self::sqr($ct)*$ct);
    $B = atan($t);
    $H = sqrt(1+self::sqr($t)) * ($p-$a/sqrt(1.+(1.-$e2)*self::sqr($t)));
    $L = 2*atan($y/($p+$x));

    return [$B, $L, $H];
  }

  ########################################################################
  # Hrdina (1997)
  static function WGS84_xyz_BLH($x, $y, $z)
  {
    $a = 6378137.0; $f_1 = 298.257223563;

    list($B, $L, $H) = self::Ellipsoid_xyz_BLH($x, $y, $z, $a, $f_1);
    return [$B, $L, $H];
  }

  ########################################################################
  # Hrdina (1997)
  static function Bessel_xyz_BLH($x, $y, $z)
  {
    $a = 6377397.15508; $f_1 = 299.152812853;

    list($B, $L, $H) = self::Ellipsoid_xyz_BLH($x, $y, $z, $a, $f_1);
    return [$B, $L, $H];
  }

  ########################################################################
  # Hrdina (1997)
  static function Krasovskij_xyz_BLH($x, $y, $z)
  {
    $a = 6378245.0; $f_1 = 298.3;

    list($B, $L, $H) = self::Ellipsoid_xyz_BLH($x, $y, $z, $a, $f_1);
    return [$B, $L, $H];
  }

  ########################################################################
  # Hrdina (1997)
  static function Ellipsoid_BLH_xyz($phi, $lambda, $H, $a, $f_1)
  {
    $e2 = 1.-self::sqr(1.-1./$f_1);
    $rho = $a/sqrt(1. - $e2*self::sqr(sin($phi)));
    $x = ($rho+$H) * cos($phi)*cos($lambda);
    $y = ($rho+$H) * cos($phi)*sin($lambda);
    $z = ((1.-$e2)*$rho + $H) * sin($phi);

    return [$x, $y, $z];
  }

  ########################################################################
  # Hrdina (2002)
  static function Bessel_BLH_xyz($phi, $lambda, $H)
  {
    $a = 6377397.15508; $f_1 = 299.152812853;

    list($x, $y, $z) = self::Ellipsoid_BLH_xyz($phi, $lambda, $H, $a, $f_1);
    return [$x, $y, $z];
  }

  ########################################################################
  # Hrdina (1997)
  static function Krasovskij_BLH_xyz($phi, $lambda, $H)
  {
    $a = 6378245.0; $f_1 = 298.3;

    list($x, $y, $z) = self::Ellipsoid_BLH_xyz($phi, $lambda, $H, $a, $f_1);
    return [$x, $y, $z];
  }

  ########################################################################
  # Hrdina (1997)
  static function WGS84_BLH_xyz($phi, $lambda, $H)
  {
    $a = 6378137.0; $f_1 = 298.257223563;	# parameters of the WGS-84 ellipsoid

    list($x, $y, $z) = self::Ellipsoid_BLH_xyz($phi, $lambda, $H, $a, $f_1);
    return [$x, $y, $z];
  }

  ########################################################################
  # CALCULATION OF PLANAR COORDINATES (NORTHINGS AND EASTINGS)
  ########################################################################
  # Ref: Hrdina, Z.: Transformace souradnic ze systemu WGS-84
  # do systemu S-JTSK. Praha: CVUT, 1997.
  # http://www.geospeleos.com/Mapovani/WGS84toSJTSK/WGS_JTSK.pdf

  static function Krovak_BLH_XY($B, $L)
  {
    # Krovak projection (used in the S-JTSK coordinate system)
    $a = 6377397.15508; $e = 0.081696831215303;
    $n = 0.97992470462083; $konst_u_ro = 12310230.12797036;
    $sinUQ = 0.863499969506341; $cosUQ = 0.504348889819882;
    $sinVQ = 0.420215144586493; $cosVQ = 0.907424504992097;
    $alfa = 1.000597498371542; $k_2 = 1.00685001861538;

    $sinB = sin($B); $t = (1-$e*$sinB)/(1+$e*$sinB);
    $t = self::sqr(1+$sinB)/(1-self::sqr($sinB)) * exp($e*log($t)); $t = $k_2*exp($alfa*log($t));
    $sinU = ($t-1)/($t+1); $cosU = sqrt(1-self::sqr($sinU));
    $V = $alfa*$L; $sinV = sin($V); $cosV = cos($V);
    $cosDV = $cosVQ*$cosV + $sinVQ*$sinV; $sinDV = $sinVQ*$cosV - $cosVQ*$sinV;
    $sinS = $sinUQ*$sinU + $cosUQ*$cosU*$cosDV; $cosS = sqrt(1-self::sqr($sinS));
    $sinD = $sinDV*$cosU/$cosS; $D = atan($sinD/sqrt(1-self::sqr($sinD)));
    $epsilon = $n*$D; $ro = $konst_u_ro*exp(-$n*log((1+$sinS)/$cosS));
    $X = $ro*cos($epsilon); $Y = $ro*sin($epsilon);

    return [$X, $Y];
  }

  ########################################################################
  # Ref: Hrdina, Z.: Prepocet z S-JTSK do WGS-84. 2002.
  # http://gpsweb.cz/JTSK-WGS.htm.
  static function Krovak_XY_BLH($X, $Y)
  {
    # inverse Krovak projection (used in the S-JTSK coordinate system)
    $a = 6377397.15508; $e = 0.081696831215303;
    $n = 0.97992470462083; $konst_u_ro = 12310230.12797036;
    $sinUQ = 0.863499969506341; $cosUQ = 0.504348889819882;
    $sinVQ = 0.420215144586493; $cosVQ = 0.907424504992097;
    $alfa = 1.000597498371542; $k_2 = 1.003419163966575;

    $ro = sqrt(self::sqr($X)+self::sqr($Y));
    $epsilon = 2.*atan($Y/($ro+$X));
    $D = $epsilon/$n; $S = 2.*atan(exp(1./$n*log($konst_u_ro/$ro)))-self::$pi/2.;
    $sinS = sin($S); $cosS = cos($S);
    $sinU = $sinUQ*$sinS-$cosUQ*$cosS*cos($D); $cosU = sqrt(1.-self::sqr($sinU));
    $sinDV = sin($D)*$cosS/$cosU; $cosDV = sqrt(1.-self::sqr($sinDV));
    $sinV = $sinVQ*$cosDV-$cosVQ*$sinDV; $cosV = $cosVQ*$cosDV+$sinVQ*$sinDV;
    $L = 2.*atan($sinV/(1.+$cosV))/$alfa;
    $t = exp(2./$alfa*log((1.+$sinU)/$cosU/$k_2));
    $pom = ($t-1.)/($t+1.);
    do {
     $sinB = $pom;
     $pom = $t*exp($e*log((1.+$e*$sinB)/(1.-$e*$sinB)));
     $pom = ($pom-1)/($pom+1);
    } while (abs($pom-$sinB) > 1.e-15);
    $B = atan($pom/sqrt(1.-self::sqr($pom)));

    $H = 0.;
    #  $H = $H + 45;	# this is an appropriate heigth correction
    return [$B, $L, $H];
  }

  ########################################################################
  # Ref: Converting between grid eastings and northings
  # and ellipsoidal latititude and longitude.
  # http://www.gps.gov.uk/guidec.asp
  static function TransverseMercator_BLH_XY($phi, $lambda, $N0, $E0, $F0, $phi0, $lambda0, $a, $b)
  {
    $e2 = (self::sqr($a) - self::sqr($b))/self::sqr($a);
    $n = ($a-$b)/($a+$b);
    $sinphi = sin($phi);
    $sinphi2 = self::sqr($sinphi);
    $e2sinphi2 = 1-$e2*self::sqr($sinphi);
    $nu = $a*$F0/sqrt($e2sinphi2);
    $rho = $a*$F0*(1-$e2)/sqrt(self::sqr($e2sinphi2)*$e2sinphi2);
    $eta2 = $nu/$rho - 1;

    $n2 = self::sqr($n);
    $n3 = $n*$n2;
    $M = $b*$F0*(
      (1 + $n + 5/4*$n2 + 5/4*$n3) * ($phi-$phi0)
      - (3*$n + 3*$n2 + 21/8*$n3) * sin($phi-$phi0)*cos($phi+$phi0)
      + (15/8*$n2 + 15/8*$n3) * sin(2*($phi-$phi0))*cos(2*($phi+$phi0))
      - 35/24*$n3 * sin(3*($phi-$phi0))*cos(3*($phi-$phi0))
      );

    $cosphi = cos($phi);
    $cosphi3 = pow($cosphi,3);
    $cosphi5 = pow($cosphi,5);
    $tanphi2 = self::sqr(tan($phi));
    $tanphi4 = self::sqr($tanphi2);
    $I = $M + $N0;
    $II = $nu/2 * $sinphi*$cosphi;
    $III = $nu/24 * $sinphi*$cosphi3 * (5 - $tanphi2 + 9*$eta2);
    $IIIA = $nu/720 * $sinphi*$cosphi5 * (61 - 58*$tanphi2 + $tanphi4);
    $IV = $nu * $cosphi;
    $V = $nu/6 * $cosphi3 * ($nu/$rho - $tanphi2);
    $VI = $nu/120 * $cosphi5 * (5 - 18*$tanphi2 + $tanphi4 + 14*$eta2 - 58*$tanphi2*$eta2);

    $ll0 = $lambda - $lambda0;
    $N = $I + $II*pow($ll0,2) + $III*pow($ll0,4) + $IIIA*pow($ll0,6);
    $E = $E0 + $IV*$ll0 + $V*pow($ll0,3) + $VI*pow($ll0,5);

    return [$E, $N];
  }

  ########################################################################
  # Ref: Converting between grid eastings and northings
  # and ellipsoidal latititude and longitude.
  # http://www.gps.gov.uk/guidec.asp
  static function Mercator_XY_BLH($E, $N, $N0, $E0, $F0, $phi0, $lambda0, $a, $b)
  {
    $phid = ($N-$N0)/($a*$F0) + $phi0;

    $n = ($a-$b)/($a+$b);
    $n2 = self::sqr($n);
    $n3 = $n*$n2;

    $i1st = 0;
    do {

      if ($i1st > 0) $phid = ($N-$N0-$M)/($a*$F0) + $phid;
      $i1st++;

      $M = $b*$F0*(
        (1 + $n + 5/4*$n2 + 5/4*$n3) * ($phid-$phi0)
        - (3*$n + 3*$n2 + 21/8*$n3) * sin($phid-$phi0)*cos($phid+$phi0)
        + (15/8*$n2 + 15/8*$n3) * sin(2*($phid-$phi0))*cos(2*($phid+$phi0))
        - 35/24*$n3 * sin(3*($phid-$phi0))*cos(3*($phid-$phi0))
        );

    } while (abs($N-$N0-$M) > 1.e-4);

    $e2 = (self::sqr($a)-self::sqr($b))/self::sqr($a);
    $sinphi = sin($phid);	# tady je zrejme phi'
    $sinphi2 = self::sqr($sinphi);
    $e2sinphi2 = 1-$e2*self::sqr($sinphi);
    $nu = $a*$F0/sqrt($e2sinphi2);
    $rho = $a*$F0*(1-$e2)/sqrt(self::sqr($e2sinphi2)*$e2sinphi2);
    $eta2 = $nu/$rho - 1;

    $nu2 = self::sqr($nu);
    $nu3 = $nu*$nu2;
    $nu5 = $nu3*$nu2;
    $nu7 = $nu5*$nu2;
    $secphid = 1./cos($phid);
    $tanphid = tan($phid);
    $tanphid2 = self::sqr($tanphid);
    $tanphid4 = self::sqr($tanphid2);
    $tanphid6 = $tanphid4*$tanphid2;

    $VII = $tanphid/(2*$rho*$nu);
    $VIII = $tanphid/(24*$rho*$nu3) * (5 + 3*$tanphid2 + $eta2 - 9*$tanphid2*$eta2);
    $IX = $tanphid/(720*$rho*$nu5) * (61 + 90*$tanphid2 + 45*$tanphid4);
    $X = $secphid/$nu;
    $XI = $secphid/(6*$nu3) * ($nu/$rho + 2*$tanphid2);
    $XII = $secphid/(120*$nu5) * (5 + 28*$tanphid2 + 24*$tanphid4);
    $XIIA = $secphid/(5040*$nu7) * (61 + 662*$tanphid2 + 1320*$tanphid4 + 720*$tanphid6);

    $EE0 = $E - $E0;
    $phi = $phid - $VII*pow($EE0,2) + $VIII*pow($EE0,4) - $IX*pow($EE0,6);
    $lambda = $lambda0 + $X*$EE0 - $XI*pow($EE0,3) + $XII*pow($EE0,5) - $XIIA*pow($EE0,7);

    return [$phi, $lambda];
  }

  ########################################################################
  static function S42_3_BLH_XY($phi, $lambda)
  {
    # an application of the Mercator projection for the S42 coordinate system
    # and the 3rd belt suitable for the Czech Republic

    # parameters of the Krasovskij ellipsoid
    $a = 6378245.0; $f = 298.3;

    # parameters of the S-42 coordinate system (3rd belt)
    $N0 = 0;
    $E0 = 3500000;
    $F0 = 1;
    $phi0 = deg2rad(0);
    $lambda0 = deg2rad(15);

    $b = $a - $a/$f;

    list($E, $N) = self::TransverseMercator_BLH_XY($phi, $lambda, $N0, $E0, $F0, $phi0, $lambda0, $a, $b);
    return [$E, $N];
  }

  ########################################################################
  static function S42_3_XY_BLH($E, $N)
  {
    # an application of the Mercator projection for the S42 coordinate system
    # and the 3rd belt suitable for the Czech Republic
    # parameters of the Krasovskij ellipsoid (again the same)
    $a = 6378245.0; $f = 298.3;

    # parameters of the S-42 coordinate system (3rd belt)
    $N0 = 0;
    $E0 = 3500000;
    $F0 = 1;
    $phi0 = deg2rad(0);
    $lambda0 = deg2rad(15);

    $b = $a - $a/$f;

    list($phi, $lambda) = self::Mercator_XY_BLH($E, $N, $N0, $E0, $F0, $phi0, $lambda0, $a, $b);
    $H = 0.;
    return [$phi, $lambda, $H];
  }

  ########################################################################
  static function S42_4_BLH_XY($phi, $lambda)
  {
    # an application of the Mercator projection for the S42 coordinate system
    # and the 4th belt (suitable for Slovakia)

    # parameters of the Krasovskij ellipsoid
    $a = 6378245.0; $f = 298.3;

    # parameters of the S-42 (4th belt) coordinate system
    $N0 = 0;
    $E0 = 4500000;
    $F0 = 1;
    $phi0 = deg2rad(0);
    $lambda0 = deg2rad(21);

    $b = $a - $a/$f;

    list($E, $N) = self::TransverseMercator_BLH_XY($phi, $lambda, $N0, $E0, $F0, $phi0, $lambda0, $a, $b);
    return [$E, $N];
  }

  ########################################################################
  static function S42_4_XY_BLH($E, $N)
  {
    # an application of the Mercator projection for the S42 coordinate system
    # and 4th belt (suitable for Slovakia)

    # parameters of the Krasovskij ellipsoid (again the same)
    $a = 6378245.0; $f = 298.3;

    # parameters of the S-42 (4th belt) coordinate system
    $N0 = 0;
    $E0 = 4500000;
    $F0 = 1;
    $phi0 = deg2rad(0);
    $lambda0 = deg2rad(21);

    $b = $a - $a/$f;

    list($phi, $lambda) = self::Mercator_XY_BLH($E, $N, $N0, $E0, $F0, $phi0, $lambda0, $a, $b);
    $H = 0.;
    return [$phi, $lambda, $H];
  }


  ########################################################################
  # HI-LEVEL TRANSFORMATION ROUTINES (LAT, LON, HT -> PLANAR X, Y)
  ########################################################################
  static function WGS84_SJTSK_transformation_BLH_XY($B, $L, $H)
  {
    list($x1, $y1, $z1) = self::WGS84_BLH_xyz($B, $L, $H);
    list($x2, $y2, $z2) = self::WGS84_SJTSK_transformation_xyz_xyz($x1, $y1, $z1);
    list($B, $L, $H) = self::Bessel_xyz_BLH($x2, $y2, $z2);
    list($X, $Y) = self::Krovak_BLH_XY($B, $L);

    return [$X, $Y];
  }

  ########################################################################
  static function SJTSK_WGS84_transformation_XY_BLH($X, $Y)
  {
    list($B, $L, $H) = self::Krovak_XY_BLH($X, $Y);
    list($x2, $y2, $z2) = self::Bessel_BLH_xyz($B, $L, $H);
    list($x1, $y1, $z1) = self::SJTSK_WGS84_transformation_xyz_xyz($x2, $y2, $z2);
    list($B, $L, $H) = self::WGS84_xyz_BLH($x1, $y1, $z1);

    return [$B, $L, $H];
  }

  static function SJTSK_WGS84($X, $Y)
  {
    list($B, $L, $H) = self::SJTSK_WGS84_transformation_XY_BLH($X, $Y);
    $B = rad2deg($B);
    $L = rad2deg($L);

    return ['lat' => $B, 'lng' => $L];
  }

  ########################################################################
  static function WGS84_S42_3_transformation_BLH_XY($B, $L, $H)
  {
    list($x1, $y1, $z1) = self::WGS84_BLH_xyz($B, $L, $H);
    list($x2, $y2, $z2) = self::WGS84_S42_transformation_xyz_xyz($x1, $y1, $z1);
    list($B, $L, $H) = self::Krasovskij_xyz_BLH($x2, $y2, $z2);
    list($E, $N) = self::S42_3_BLH_XY($B, $L);

    return [$E, $N];
  }

  ########################################################################
  static function S42_3_WGS84_transformation_XY_BLH($E, $N)
  {
    list($B, $L, $H) = self::S42_3_XY_BLH($E, $N);
    list($x2, $y2, $z2) = self::Krasovskij_BLH_xyz($B, $L, $H);
    list($x1, $y1, $z1) = self::S42_WGS84_transformation_xyz_xyz($x2, $y2, $z2);
    list($B, $L, $H) = self::WGS84_xyz_BLH($x1, $y1, $z1);

    return [$B, $L, $H];
  }

  ########################################################################
  static function WGS84_S42_4_transformation_BLH_XY($B, $L, $H)
  {
    list($x1, $y1, $z1) = self::WGS84_BLH_xyz($B, $L, $H);
    list($x2, $y2, $z2) = self::WGS84_S42_transformation_xyz_xyz($x1, $y1, $z1);
    list($B, $L, $H) = self::Krasovskij_xyz_BLH($x2, $y2, $z2);
    list($E, $N) = self::S42_4_BLH_XY($B, $L);

    return [$E, $N];
  }

  ########################################################################
  static function S42_4_WGS84_transformation_XY_BLH($E, $N)
  {
    list($B, $L, $H) = self::S42_4_XY_BLH($E, $N);
    list($x2, $y2, $z2) = self::Krasovskij_BLH_xyz($B, $L, $H);
    list($x1, $y1, $z1) = self::S42_WGS84_transformation_xyz_xyz($x2, $y2, $z2);
    list($B, $L, $H) = self::WGS84_xyz_BLH($x1, $y1, $z1);

    return [$B, $L, $H];
  }

  ########################################################################
  static function dmsd($s)
  {
    $l = explode(":", $s);
    if (sizeof($l) == 1)
      return $s;
    else {
      if (substr($s,0,1) == "-") { $sgn=-1; } else { $sgn=1; }
      if (sizeof($l) == 2)
        return $sgn*(abs($l[0]) + $l[1]/60);
      else if (sizeof($l) == 3)
        return $sgn*(abs($l[0]) + $l[1]/60 + $l[2]/3600);
    }
  }

  static function ddms1($x)
  {
    if ($x > 0) $sign = ""; else $sign = "-";
    $x = abs($x);
    $d = floor($x);
    $m = floor(60.*($x - $d));
    $s = sprintf("%.1f", 3600.*($x - $d - $m/60.));
    return "$sign$d:$m:$s";
  }

  static function convert_WGS84_SJTSK ($lat, $long, $alt = 230)
  {
    $B = deg2rad(self::dmsd($lat));
    $L = deg2rad(self::dmsd($long));
    $H = $alt;

    list($X, $Y) = self::WGS84_SJTSK_transformation_BLH_XY($B, $L, $H);

    $B = self::ddms1(rad2deg($B));
    $L = self::ddms1(rad2deg($L));

    return ['X' => $X, 'Y' => $Y, 'lat' => $B, 'lng'=> $L];
  }
}