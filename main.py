import time
import math
import board
import busio
from datetime import datetime
import adafruit_bme280
import adafruit_scd4x
import adafruit_bh1750
import adafruit_ads1x15.ads1115 as ADS
from adafruit_ads1x15.analog_in import AnalogIn
import RPi.GPIO as GPIO
import os

# --- Konfiguráció ---
WATER_PUMP_PIN = 13
AIR_PUMP_PIN = 12
PWM_FREQ = 1000

MOISTURE_START_THRESHOLD = 30
MOISTURE_STOP_THRESHOLD = 75

AIR_PUMP_TIMES = ["08:00", "14:00", "20:00"]
AIR_PUMP_DURATION = 600  # másodperc

SZARAZ_RAW = 26000.0
VIZES_RAW = 10000.0

# --- I2C init ---
try:
    i2c = busio.I2C(board.SCL, board.SDA)
except Exception as e:
    print("I2C hiba:", e)
    i2c = None

# --- SCD40 CO2 ---
scd40 = None
if i2c:
    try:
        scd40 = adafruit_scd4x.SCD4X(i2c)
        scd40.start_periodic_measurement()
    except Exception:
        scd40 = None

# --- BH1750 fényérzékelők ---
bh1750 = None
bh1750_v2 = None
if i2c:
    try:
        bh1750 = adafruit_bh1750.BH1750(i2c, address=0x23)
    except Exception:
        bh1750 = None
    try:
        bh1750_v2 = adafruit_bh1750.BH1750(i2c, address=0x5C)
    except Exception:
        bh1750_v2 = None

# --- BME280 ---
bme280_sensor = None
if i2c:
    try:
        bme280_sensor = adafruit_bme280.BME280(i2c, address=0x76)
    except Exception as e:
        print("BME280 init hiba:", e)
        bme280_sensor = None

# --- ADS1115 ADC ---
ads = None
chan = None
if i2c:
    try:
        ads = ADS.ADS1115(i2c, address=0x48)
        ads.gain = 1
        chan = AnalogIn(ads, 0)
    except Exception:
        ads = None
        chan = None

# --- GPIO ---
GPIO.setmode(GPIO.BCM)
GPIO.setup(WATER_PUMP_PIN, GPIO.OUT)
GPIO.setup(AIR_PUMP_PIN, GPIO.OUT)

pwm_water = GPIO.PWM(WATER_PUMP_PIN, PWM_FREQ)
pwm_air   = GPIO.PWM(AIR_PUMP_PIN, PWM_FREQ)
pwm_water.start(0)
pwm_air.start(0)

MUX_PINS = [22, 27, 17, 23]
for p in MUX_PINS:
    GPIO.setup(p, GPIO.OUT, initial=0)

# --- Motor vezérlés ---
def soft_start(pwm_obj, target_duty=85, duration=2.0):
    steps = 20
    step_time = duration / steps
    for i in range(steps + 1):
        pwm_obj.ChangeDutyCycle((target_duty / steps) * i)
        time.sleep(step_time)

def soft_stop(pwm_obj, current_duty=90, duration=1.0):
    steps = 10
    step_time = duration / steps
    for i in range(steps, -1, -1):
        pwm_obj.ChangeDutyCycle((current_duty / steps) * i)
        time.sleep(step_time)

# --- Segédfüggvények ---
def select_channel(channel_index: int):
    binary_select = format(channel_index, '04b')
    for i in range(4):
        GPIO.output(MUX_PINS[i], int(binary_select[3 - i]))

def read_mux_channel_avg(channel, samples=6, delay=0.01):
    if chan is None:
        return (None, None)
    raws, volts = [], []
    for _ in range(samples):
        select_channel(channel)
        time.sleep(delay)
        raws.append(chan.value)
        volts.append(chan.voltage if chan.voltage is not None else 0.0)
    return (sum(raws)/len(raws), sum(volts)/len(volts))

def read_moisture():
    if chan is None:
        return 0.0
    soil_raws = [read_mux_channel_avg(ch)[0] or 0.0 for ch in (0,1,2)]
    avg_raw = sum(soil_raws)/len(soil_raws)
    pct = (SZARAZ_RAW - avg_raw) / (SZARAZ_RAW - VIZES_RAW) * 100.0
    return max(0.0, min(100.0, pct))

def read_water_level():
    if chan is None:
        return (None, None, None)
    avg_raw, avg_volt = read_mux_channel_avg(3)
    WATER_EMPTY_VOLT = 0.20
    WATER_FULL_VOLT  = 2.80
    if avg_volt is None:
        pct = None
    else:
        pct = (avg_volt - WATER_EMPTY_VOLT) / (WATER_FULL_VOLT - WATER_EMPTY_VOLT) * 100.0
        pct = max(0.0, min(100.0, pct))
    return (avg_raw, avg_volt, pct)

def calculate_vpd(temp_c, rh_percent):
    es = 0.6108 * math.exp((17.27*temp_c)/(temp_c+237.3))
    ea = es*(rh_percent/100.0)
    return es - ea

def check_cmd(which):
    path = f"/home/dev/oasis/cmd_{which}.txt"
    if os.path.exists(path):
        try:
            with open(path, "r") as fh:
                cmd = fh.read().strip().lower()
            if cmd == "on":
                if which == "water":
                    soft_start(pwm_water, 85, 2.0)
                else:
                    soft_start(pwm_air, 85, 2.0)
                # jelzés: parancs feldolgozva
                with open(path, "w") as fh:
                    fh.write("done\n")
            elif cmd == "off":
                if which == "water":
                    soft_stop(pwm_water, 85, 1.5)
                else:
                    soft_stop(pwm_air, 85, 1.5)
                with open(path, "w") as fh:
                    fh.write("done\n")
        except Exception as e:
            print("cmd check error", e)


# --- Állapot változók ---
water_running = False
air_running = False
air_start_time = 0
last_air_trigger_hour = -1

# --- Fő ciklus ---
try:
    print("Indul: Szenzoradatok gyűjtése (Hibatűrő mód)")
    print("="*88)

    while True:
        now = datetime.now()
        current_time_str = now.strftime("%H:%M")
        current_hour = now.hour
        napszak = "NAPPAL" if 6 <= current_hour < 20 else "ÉJSZAKA"

        # --- SCD40 ---
        co2 = temp_co2 = hum_co2 = None
        if scd40:
            try:
                if scd40.data_ready:
                    co2 = scd40.CO2
                    temp_co2 = scd40.temperature
                    hum_co2 = scd40.relative_humidity
            except Exception:
                co2 = None

        # --- MUX/ADC ---
        results = {}
        for ch in range(16):
            try:
                avg_raw, avg_volt = read_mux_channel_avg(ch)
                results[f"C{ch}"] = (avg_raw, avg_volt, ch<=3)
            except Exception:
                results[f"C{ch}"] = (None, None, False)

        soil_raws = [results[f"C{i}"][0] or 0.0 for i in (0,1,2)]
        soil_avg_raw = sum(soil_raws)/len(soil_raws)
        moisture_pct = max(0.0, min(100.0,(SZARAZ_RAW - soil_avg_raw)/(SZARAZ_RAW-VIZES_RAW)*100.0))

        # --- BME280 ---
        if bme280_sensor:
            temp = bme280_sensor.temperature
            hum  = bme280_sensor.humidity
            pres = bme280_sensor.pressure
        else:
            temp = hum = pres = None

        # --- BH1750 ---
        lux_values = []
        for sensor in (bh1750, bh1750_v2):
            try:
                if sensor: lux_values.append(sensor.lux)
            except Exception: pass
        avg_lux = sum(lux_values)/len(lux_values) if lux_values else 0.0

        # --- Öntözés ---
        if moisture_pct < MOISTURE_START_THRESHOLD and not water_running:
            soft_start(pwm_water, 85, 3.0)
            water_running = True
        elif moisture_pct >= MOISTURE_STOP_THRESHOLD and water_running:
            soft_stop(pwm_water, 85, 1.5)
            water_running = False

        # --- Légpumpa ---
        should_start_air = any(current_time_str==t for t in AIR_PUMP_TIMES)
        if should_start_air and not air_running and current_hour!=last_air_trigger_hour:
            soft_start(pwm_air,85,3.0)
            air_running=True
            air_start_time=time.monotonic()
            last_air_trigger_hour=current_hour
        if air_running and time.monotonic()-air_start_time >= AIR_PUMP_DURATION:
            soft_stop(pwm_air,85,1.5)
            air_running=False

        # --- Kiírás ---
        now_str = now.strftime('%Y-%m-%d %H:%M:%S')
        print(f"Idő: {now_str} | Napszak: {napszak}")
        print("-"*88)

        def fmt_chan(ch):
            raw, volt, avg_flag = results[f"C{ch}"]
            raw_s = f"{raw:.0f}" if raw is not None else "N/A"
            volt_s = f"{volt:.3f} V" if volt is not None else "N/A"
            tag = "avg" if avg_flag else "one"
            return f"C{ch}: {raw_s}/{volt_s} ({tag})"

        print("Talaj érzékelők (C0..C3):")
        """print(f"  {fmt_chan(0)} | {fmt_chan(1)} | {fmt_chan(2)} | {fmt_chan(3)}")"""
        print(f"    Átlag talaj nedvesség: {moisture_pct:.1f}%")

        _, _, water_pct = read_water_level()
        water_pct_s = f"{water_pct:.1f}%" if water_pct is not None else "N/A"
        print(f"    Vízszint (C03): {water_pct_s}")

        print(f"    Fény: {avg_lux:.1f} lx (szenzor1: {'OK' if bh1750 else 'N/A'}, szenzor2: {'OK' if bh1750_v2 else 'N/A'})")

        if temp is not None:
            print(f"    Hőm: {temp:.2f} °C | Pára: {hum:.2f}% | Nyomás: {pres:.2f} hPa")
        else:
            print("+BME280: Nem elérhető")

        if co2 is not None:
            print(f"    CO₂: {co2} ppm | SCD40 T: {temp_co2:.1f} °C | RH: {hum_co2:.1f}%")
        else:
            print("+CO₂: Nem elérhető")

        print(f"    Víz pumpa: {'BE' if water_running else 'KI'} | Lég pumpa: {'BE' if air_running else 'KI'}")
        print("="*88)







        log_path = "/home/dev/oasis/data.txt"

        MAX_SIZE = 5 * 1024 * 1024  # 5 MB -i hely

        if os.path.exists(log_path):
            if os.path.getsize(log_path) > MAX_SIZE:
                ts = now.strftime("%Y%m%d_%H%M%S")
                new_name = f"/home/dev/oasis/data_{ts}.txt"
                os.rename(log_path, new_name)

        with open(log_path, "a", encoding="utf-8") as f:
            f.write(f"{now_str} ")
            f.write(f"{napszak} ") #Idő & Napszak

            f.write(f"{moisture_pct:.1f} ") #Átlag talaj nedvesség

            _, _, water_pct = read_water_level()
            water_pct_s = f"{water_pct:.1f}" if water_pct is not None else "###"
            f.write(f"{water_pct_s} ") #Vízszint (C03)

            f.write(f"{avg_lux:.1f} " #Fény
                    f"{'OK' if bh1750 else '###'} "
                    f"{'OK' if bh1750_v2 else '###'} ")

            if temp is not None:
                f.write(f"{temp:.2f} {hum:.2f} {pres:.2f} ") #Hő, Pára, Nyomás
            else:
                f.write("### ")

            if co2 is not None:
                f.write(f"{co2} {temp_co2:.1f} {hum_co2:.1f} ") #  CO₂, SCD40 hő, 
            else:
                f.write("### ")

            f.write(f"{'True' if water_running else 'False'} "
                    f"{'True' if air_running else 'False'} \n")

        time.sleep(0.0001)

except KeyboardInterrupt:
    print("\nLeállás... (Ctrl+C)")

finally:
    try:
        pwm_water.ChangeDutyCycle(0)
        pwm_air.ChangeDutyCycle(0)
        pwm_water.stop()
        pwm_air.stop()
    except Exception:
        pass
    GPIO.cleanup()
