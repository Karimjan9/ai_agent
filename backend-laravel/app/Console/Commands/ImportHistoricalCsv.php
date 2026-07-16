<?php
namespace App\Console\Commands;
use App\Models\Candle;
use App\Models\Symbol;
use Carbon\Carbon;
use Illuminate\Console\Command;
class ImportHistoricalCsv extends Command
{
    protected $signature='market-data:import-csv {symbol} {path} {--timeframe=H1} {--provider=dukascopy-archive}';
    protected $description='Stream a large historical OHLC CSV into candles with idempotent chunked upserts';
    public function handle():int
    {$path=(string)$this->argument('path');if(!is_file($path)){$this->error('CSV not found.');return self::FAILURE;}
      $symbol=Symbol::where('code',strtoupper($this->argument('symbol')))->firstOrFail();$h=fopen($path,'rb');$first=fgets($h);$delimiter=str_contains($first,';')?';':',';$header=array_map(fn($x)=>strtolower(trim($x)),str_getcsv(trim($first),$delimiter));$batch=[];$total=0;
      while(($values=fgetcsv($h,0,$delimiter))!==false){if(count($values)!==count($header))continue;$row=array_combine($header,$values);$raw=$row['time']??$row['date']??null;if(!$raw)continue;
        $time=null;foreach(['Y.m.d H:i','Y.m.d H:i:s','Y-m-d H:i:s','Y-m-d H:i'] as $format){try{$time=Carbon::createFromFormat($format,trim($raw))->format('Y-m-d H:i:s');break;}catch(\Throwable){}}
        if(!$time)continue;$now=now();$batch[]=['symbol_id'=>$symbol->id,'timeframe'=>$this->option('timeframe'),'time'=>$time,'open'=>(float)$row['open'],'high'=>(float)$row['high'],'low'=>(float)$row['low'],'close'=>(float)$row['close'],'volume'=>(float)($row['volume']??0),'provider'=>$this->option('provider'),'created_at'=>$now,'updated_at'=>$now];
        if(count($batch)>=2000){$this->save($batch);$total+=count($batch);$batch=[];}}
      if($batch){$this->save($batch);$total+=count($batch);}fclose($h);$this->info("Imported {$total} {$symbol->code} candles.");return self::SUCCESS;}
    private function save(array $rows):void{Candle::upsert($rows,['symbol_id','timeframe','time'],['open','high','low','close','volume','provider','updated_at']);}
}
