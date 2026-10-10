<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\ApiClient;
use P202Cli\Application;
use P202Cli\Commands\BaseCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Tests\TestCase;

/**
 * Options are kebab-case now, and a script written with the snake_case
 * spelling they had sends exactly what it sent before. Each case is argv as
 * such a script wrote it, with the request the CLI built from it before the
 * rename (recorded from the unchanged code, key order and JSON types
 * included). Both spellings run through Application::run() with no input
 * given, the path bin/p202 takes, so the alias is KebabCaseArgvInput's and
 * the request is the real command's; only the HTTP call is recorded.
 */
final class SnakeCaseOptionsStillWorkTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string}> */
    public static function scripts(): iterable
    {
        yield 'campaign:create' => [
            ['campaign:create', '--aff_campaign_name=Test', '--aff_campaign_url=https://x.test/o?a_b=1&c-d=2', '--aff_campaign_payout=1.5', '--aff_network_id=3', '--payout_mode=accumulate', '--aff_campaign_url_2=https://y.test', '--identity_signals=1'],
            '[["POST","campaigns",{"aff_campaign_name":"Test","aff_campaign_url":"https://x.test/o?a_b=1&c-d=2","aff_campaign_url_2":"https://y.test","aff_campaign_payout":"1.5","aff_network_id":"3","payout_mode":"accumulate","identity_signals":"1"}]]',
        ];
        yield 'campaign:update' => [
            ['campaign:update', '7', '--aff_campaign_name=New', '--aff_campaign_foreign_payout', '2.25', '--aff_campaign_rotate=0'],
            '[["PUT","campaigns/7",{"aff_campaign_name":"New","aff_campaign_foreign_payout":"2.25","aff_campaign_rotate":"0"}]]',
        ];
        yield 'campaign:list' => [
            ['campaign:list', '--filter[aff_network_id]=3', '--limit=10'],
            '[["GET","campaigns",{"limit":"10","offset":"0","filter[aff_network_id]":"3"}]]',
        ];
        yield 'tracker:list' => [
            ['tracker:list', '--filter[aff_campaign_id]=5', '--filter[ppc_account_id]', '6'],
            '[["GET","trackers",{"limit":"50","offset":"0","filter[aff_campaign_id]":"5","filter[ppc_account_id]":"6"}]]',
        ];
        yield 'tracker:create' => [
            ['tracker:create', '--aff_campaign_id=5', '--ppc_account_id=6', '--text_ad_id=7', '--landing_page_id=8', '--rotator_id=9', '--click_cpc=0.1', '--click_cpa=2', '--click_cloaking=1'],
            '[["POST","trackers",{"aff_campaign_id":"5","ppc_account_id":"6","text_ad_id":"7","landing_page_id":"8","rotator_id":"9","click_cpc":"0.1","click_cpa":"2","click_cloaking":"1"}]]',
        ];
        yield 'ppc-account:create' => [
            ['ppc-account:create', '--ppc_account_name=A', '--ppc_network_id=2', '--ppc_account_default=1'],
            '[["POST","ppc-accounts",{"ppc_account_name":"A","ppc_network_id":"2","ppc_account_default":"1"}]]',
        ];
        yield 'ppc-account:list' => [
            ['ppc-account:list', '--filter[ppc_network_id]=2'],
            '[["GET","ppc-accounts",{"limit":"50","offset":"0","filter[ppc_network_id]":"2"}]]',
        ];
        yield 'ppc-network:create' => [
            ['ppc-network:create', '--ppc_network_name=Net'],
            '[["POST","ppc-networks",{"ppc_network_name":"Net"}]]',
        ];
        yield 'aff-network:update' => [
            ['aff-network:update', '4', '--aff_network_name=Aff', '--dni_network_id=12'],
            '[["PUT","aff-networks/4",{"aff_network_name":"Aff","dni_network_id":"12"}]]',
        ];
        yield 'landing-page:create' => [
            ['landing-page:create', '--landing_page_url=https://lp.test', '--aff_campaign_id=5', '--leave_behind_page_url=https://lb.test', '--landing_page_nickname=Nick', '--landing_page_type=1'],
            '[["POST","landing-pages",{"landing_page_url":"https://lp.test","aff_campaign_id":"5","landing_page_nickname":"Nick","leave_behind_page_url":"https://lb.test","landing_page_type":"1"}]]',
        ];
        yield 'landing-page:list' => [
            ['landing-page:list', '--filter[aff_campaign_id]=5'],
            '[["GET","landing-pages",{"limit":"50","offset":"0","filter[aff_campaign_id]":"5"}]]',
        ];
        yield 'text-ad:update' => [
            ['text-ad:update', '4', '--text_ad_headline=H', '--landing_page_id', '9', '--text_ad_display_url=d.test', '--text_ad_description=D', '--text_ad_type=0', '--text_ad_name=N'],
            '[["PUT","text-ads/4",{"text_ad_name":"N","text_ad_headline":"H","text_ad_description":"D","text_ad_display_url":"d.test","landing_page_id":"9","text_ad_type":"0"}]]',
        ];
        yield 'text-ad:list' => [
            ['text-ad:list', '--filter[aff_campaign_id]=5'],
            '[["GET","text-ads",{"limit":"50","offset":"0","filter[aff_campaign_id]":"5"}]]',
        ];
        yield 'report:summary' => [
            ['report:summary', '--period=last7', '--time_from=100', '--time_to=200', '--aff_campaign_id=5', '--ppc_account_id=6'],
            '[["GET","reports/summary",{"period":"last7","time_from":"100","time_to":"200","aff_campaign_id":"5","ppc_account_id":"6"}]]',
        ];
        yield 'report:breakdown' => [
            ['report:breakdown', '--breakdown=country', '--sort_dir=ASC', '--aff_network_id=1', '--ppc_network_id=2', '--landing_page_id=3', '--country_id=4', '--time_from', '100'],
            '[["GET","reports/breakdown",{"time_from":"100","aff_network_id":"1","ppc_network_id":"2","landing_page_id":"3","country_id":"4","breakdown":"country","sort":"total_clicks","sort_dir":"ASC","limit":"50","offset":"0"}]]',
        ];
        yield 'report:timeseries' => [
            ['report:timeseries', '--interval=hour', '--time_from=100', '--aff_campaign_id=5', '--ppc_account_id=6', '--aff_network_id=1', '--ppc_network_id=2', '--landing_page_id=3', '--country_id=4', '--time_to=200'],
            '[["GET","reports/timeseries",{"time_from":"100","time_to":"200","aff_campaign_id":"5","ppc_account_id":"6","aff_network_id":"1","ppc_network_id":"2","landing_page_id":"3","country_id":"4","interval":"hour"}]]',
        ];
        yield 'report:daypart' => [
            ['report:daypart', '--sort_dir=DESC', '--time_to=200', '--aff_campaign_id=5'],
            '[["GET","reports/daypart",{"time_to":"200","aff_campaign_id":"5","sort":"hour_of_day","sort_dir":"DESC"}]]',
        ];
        yield 'report:weekpart' => [
            ['report:weekpart', '--sort=total_clicks', '--sort_dir=DESC', '--country_id=4', '--time_from=100'],
            '[["GET","reports/weekpart",{"time_from":"100","country_id":"4","sort":"total_clicks","sort_dir":"DESC"}]]',
        ];
        yield 'ltv:summary' => [
            ['ltv:summary', '--time_from=100', '--time_to=200'],
            '[["GET","ltv/summary",{"time_from":"100","time_to":"200"}]]',
        ];
        yield 'ltv:breakdown' => [
            ['ltv:breakdown', '--by=product', '--time_from=100', '--time_to=200', '--limit=5'],
            '[["GET","ltv/breakdown",{"time_from":"100","time_to":"200","by":"product","limit":"5"}]]',
        ];
        yield 'ltv:customers' => [
            ['ltv:customers', '--time_from=100', '--sort=mrr', '--dir=ASC', '--time_to=200'],
            '[["GET","ltv/customers",{"time_from":"100","time_to":"200","sort":"mrr","dir":"ASC"}]]',
        ];
        yield 'ltv:predict' => [
            ['ltv:predict', '--by=campaign', '--time_to=200', '--time_from=100'],
            '[["GET","ltv/predict",{"time_from":"100","time_to":"200","by":"campaign"}]]',
        ];
        yield 'click:list' => [
            ['click:list', '--time_from=100', '--time_to=200', '--aff_campaign_id=5', '--ppc_account_id=6', '--landing_page_id=7', '--click_lead=1', '--click_bot=0'],
            '[["GET","clicks",{"limit":"50","offset":"0","time_from":"100","time_to":"200","aff_campaign_id":"5","ppc_account_id":"6","landing_page_id":"7","click_lead":"1","click_bot":"0"}]]',
        ];
        yield 'conversion:list' => [
            ['conversion:list', '--campaign_id=5', '--time_from=100', '--time_to=200', '--click_id=9', '--source=api', '--goal=3'],
            '[["GET","conversions",{"limit":"50","offset":"0","campaign_id":"5","time_from":"100","time_to":"200","click_id":"9","goal":"3","source":"api"}]]',
        ];
        yield 'conversion:create' => [
            ['conversion:create', '--click_id=9', '--payout=2.5', '--transaction_id=T_1'],
            '[["POST","conversions",{"click_id":9,"payout":2.5,"transaction_id":"T_1"}]]',
        ];
        yield 'event:send' => [
            ['event:send', '--click_id=9', '--name=purchase', '--id=E1', '--occurred_at=1700000000', '--revenue=5', '--transaction_id=T_1', '--props={"a_b":1}'],
            '[["POST","events",{"click_id":9,"events":[{"event_id":"E1","name":"purchase","occurred_at":1700000000,"revenue":5,"transaction_id":"T_1","properties":{"a_b":1}}]}]]',
        ];
        yield 'rotator:create' => [
            ['rotator:create', '--name=R', '--default_url=https://d.test', '--default_campaign=5', '--default_lp=6'],
            '[["POST","rotators",{"name":"R","default_url":"https://d.test","default_campaign":"5","default_lp":"6"}]]',
        ];
        yield 'rotator:update' => [
            ['rotator:update', '3', '--default_url=https://d.test', '--default_lp=6', '--default_campaign=5'],
            '[["PUT","rotators/3",{"default_url":"https://d.test","default_campaign":"5","default_lp":"6"}]]',
        ];
        yield 'rotator:rule:create' => [
            ['rotator:rule:create', '3', '--rule_name=US', '--splittest=1', '--criteria_json=[{"type":"country","statement":"is","value":"US"}]', '--redirects_json=[{"redirect_url":"https://r.test","weight":"100"}]'],
            '[["POST","rotators/3/rules",{"rule_name":"US","splittest":1,"criteria":[{"type":"country","statement":"is","value":"US"}],"redirects":[{"redirect_url":"https://r.test","weight":"100"}]}]]',
        ];
        yield 'attribution:breakdown' => [
            ['attribution:breakdown', '--group_by=landing_page', '--model_id=2', '--compare_model_id=3', '--time_from=100', '--time_to=200', '--limit=10'],
            '[["GET","attribution/reports/breakdown",{"group_by":"landing_page","model_id":"2","compare_model_id":"3","time_from":"100","time_to":"200","limit":"10"}]]',
        ];
        yield 'attribution:export:create' => [
            ['attribution:export:create', '--group_by=campaign', '--model_id=2', '--compare_model_id=3', '--time_from=100', '--time_to=200', '--run_at=300', '--webhook_url=https://h.test', '--webhook_secret=0123456789abcdef'],
            '[["POST","attribution/exports",{"group_by":"campaign","model_id":2,"compare_model_id":3,"time_from":100,"time_to":200,"run_at":300,"webhook_url":"https://h.test","webhook_secret":"0123456789abcdef"}]]',
        ];
        yield 'attribution:model:create' => [
            ['attribution:model:create', '--model_name=M', '--model_type=linear', '--lookback_days=14', '--weighting_config={}', '--status=active'],
            '[["POST","attribution/models",{"model_name":"M","model_type":"linear","weighting_config":{},"lookback_days":14,"status":"active"}]]',
        ];
        yield 'attribution:model:update' => [
            ['attribution:model:update', '4', '--model_name=M2', '--model_type=time_decay', '--lookback_days=7', '--weighting_config={"half_life_hours":24}'],
            '[["PUT","attribution/models/4",{"model_name":"M2","model_type":"time_decay","weighting_config":{"half_life_hours":24},"lookback_days":7}]]',
        ];
        yield 'user:create' => [
            ['user:create', '--user_name=u', '--user_email=u@x.test', '--user_pass=secret', '--user_fname=F', '--user_lname=L', '--user_timezone=UTC'],
            '[["POST","users",{"user_name":"u","user_email":"u@x.test","user_pass":"secret","user_fname":"F","user_lname":"L","user_timezone":"UTC"}]]',
        ];
        yield 'user:create #2' => [
            ['user:create', '--user_name=u', '--user_email=u@x.test'],
            '[["POST","users",{"user_name":"u","user_email":"u@x.test","user_timezone":"UTC","user_pass":"typed-secret"}]]',
        ];
        yield 'user:update' => [
            ['user:update', '5', '--user_fname=F', '--user_lname=L', '--user_email=e@x.test', '--user_timezone=UTC', '--user_active=0', '--user_pass=pw'],
            '[["PUT","users/5",{"user_fname":"F","user_lname":"L","user_email":"e@x.test","user_timezone":"UTC","user_active":"0","user_pass":"pw"}]]',
        ];
        yield 'user:update #2' => [
            ['user:update', '5', '--user_pass'],
            '[["PUT","users/5",{"user_pass":"typed-secret"}]]',
        ];
        yield 'user:prefs:update' => [
            ['user:prefs:update', '5', '--user_tracking_domain=t.test', '--user_account_currency=EUR', '--user_slack_incoming_webhook=https://s.test', '--user_daily_email=on', '--ipqs_api_key=K'],
            '[["PUT","users/5/preferences",{"user_tracking_domain":"t.test","user_account_currency":"EUR","user_slack_incoming_webhook":"https://s.test","user_daily_email":"on","ipqs_api_key":"K"}]]',
        ];
        yield 'user:role:assign' => [
            ['user:role:assign', '5', '--role_id=2'],
            '[["POST","users/5/roles",{"role_id":2}]]',
        ];
        yield 'app:report' => [
            ['app:report', '--platform=ios', '--group_by=ad-network', '--time_from=100', '--time_to=200', '--registration_ids=1,2', '--signature=valid', '--protocol=skan', '--conversion_type=download', '--ad_network_id=net', '--country_code=US', '--source_identifier=1234', '--postback_version=4.0', '--did_win=1'],
            '[["GET","apps/report",{"group_by":"ad-network","platform":"ios","time_from":"100","time_to":"200","registration_ids":"1,2","signature":"valid","protocol":"skan","conversion_type":"download","ad_network_id":"net","country_code":"US","source_identifier":"1234","version":"4.0","did_win":"1"}]]',
        ];
        yield 'app:report #2' => [
            ['app:report', '--platform=android', '--group_by=match-state', '--match_state=organic', '--integrity_state=x', '--trusted=trusted', '--test=0', '--aff_campaign_id=5', '--ctit_flag=short', '--fast_goals=1', '--registration_id=7', '--limit=3'],
            '[["GET","apps/report",{"group_by":"match-state","platform":"android","limit":"3","registration_id":"7","match_state":"organic","integrity_state":"x","trusted":"trusted","test":"0","aff_campaign_id":"5","ctit_flag":"short","fast_goals":"1"}]]',
        ];
        yield 'app:install:list' => [
            ['app:install:list', '7', '--match_state=organic', '--trusted=refuted', '--test=1', '--ctit_flag=ok', '--click_id=9', '--time_from=100', '--time_to=200'],
            '[["GET","apps/7/installs",{"limit":"50","offset":"0","match_state":"organic","trusted":"refuted","test":"1","ctit_flag":"ok","click_id":"9","time_from":"100","time_to":"200"}]]',
        ];
        yield 'app:notifications' => [
            ['app:notifications', '--registration_id=7', '--time_from=100', '--time_to=200', '--status=sent', '--kind=reached'],
            '[["GET","apps/notifications",{"limit":"50","offset":"0","registration_id":"7","status":"sent","kind":"reached","time_from":"100","time_to":"200"}]]',
        ];
        yield 'app:link' => [
            ['app:link', '7', '--campaign_id=5'],
            '[["GET","apps/7/store-link",{"campaign_id":"5"}]]',
        ];
        yield 'app:install:token' => [
            ['app:install:token', '7', '--click=9'],
            '[["GET","apps/7/install-token",{"click_id":"9"}]]',
        ];
        yield 'app:integrity:mode' => [
            ['app:integrity:mode', '7', 'observe', '--cloud-project-number=123'],
            '[["PUT","apps/7",{"integrity_mode":"observe","integrity_cloud_project_number":"123"}]]',
        ];
        yield 'ltv:abm' => [
            ['ltv:abm', '--company=Acme', '--days=30'],
            '[["GET","ltv/abm/company",{"days":"30","name":"Acme"}]]',
        ];
        yield 'user:apikey:create' => [
            ['user:apikey:create', '5', '--scope=read'],
            '[["POST","users/5/api-keys",{"scope":"read"}]]',
        ];
    }

    /**
     * @dataProvider scripts
     * @param list<string> $snakeCaseArgv
     */
    public function testBothSpellingsSendTheRequestTheSnakeCaseScriptSent(array $snakeCaseArgv, string $request): void
    {
        $kebabCaseArgv = self::kebabCase($snakeCaseArgv);
        self::assertSame([], preg_grep('/^--[^=]*_/', $kebabCaseArgv), 'the kebab-case run names no option with an underscore');

        foreach (['snake_case' => $snakeCaseArgv, 'kebab-case' => $kebabCaseArgv] as $spelling => $argv) {
            [$code, $calls, $display] = self::runCli($argv);
            self::assertSame(0, $code, $spelling . ': ' . $display);
            self::assertSame($request, json_encode($calls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $spelling);
        }
    }

    public function testEveryCommandsHelpNamesItsFlagsInKebabCase(): void
    {
        $offenders = [];
        $commands = 0;
        foreach (array_keys((new Application())->all()) as $name) {
            [$code, , $display] = self::runCli([$name, '--help']);
            self::assertSame(0, $code, $name);
            if (preg_match_all('/--[a-z0-9][a-z0-9\[-]*_[a-z0-9_\[\]-]*/i', $display, $m) > 0) {
                $offenders[] = $name . ': ' . implode(', ', array_unique($m[0]));
            }
            $commands++;
        }

        self::assertSame([], $offenders);
        self::assertGreaterThan(90, $commands, 'every registered command was asked');
        [, , $display] = self::runCli(['report:summary', '--help']);
        self::assertStringContainsString('--time-from=TIME-FROM', $display);
        [, , $display] = self::runCli(['campaign:list', '--help']);
        self::assertStringContainsString('--filter[aff-network-id]', $display);
    }

    /**
     * An option name with "_" for "-", up to its "=", before any bare "--".
     *
     * @param list<string> $argv
     * @return list<string>
     */
    private static function kebabCase(array $argv): array
    {
        $options = true;
        foreach ($argv as $i => $token) {
            if ($token === '--') {
                $options = false;
            }
            if ($options && preg_match('/^(--[^=]+)(=.*)?$/s', $token, $m) === 1) {
                $argv[$i] = str_replace('_', '-', $m[1]) . ($m[2] ?? '');
            }
        }

        return $argv;
    }

    /**
     * @param list<string> $argv without the application name
     * @return array{int, list<list<mixed>>, string}
     */
    private static function runCli(array $argv): array
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $app->getHelperSet()->set(new class () extends QuestionHelper {
            public function ask(InputInterface $input, OutputInterface $output, Question $question): mixed
            {
                return 'typed-secret';
            }
        });
        $client = new class () extends ApiClient {
            /** @var list<list<mixed>> */
            public array $calls = [];

            public function __construct()
            {
                parent::__construct('http://127.0.0.1:9', 'test-key');
            }

            public function get(string $path, array $params = []): array
            {
                $this->calls[] = ['GET', $path, $params];
                return ['data' => []];
            }

            public function post(string $path, array $body = []): array
            {
                $this->calls[] = ['POST', $path, $body];
                return ['data' => []];
            }

            public function put(string $path, array $body = []): array
            {
                $this->calls[] = ['PUT', $path, $body];
                return ['data' => []];
            }

            public function patch(string $path, array $body = []): array
            {
                $this->calls[] = ['PATCH', $path, $body];
                return ['data' => []];
            }

            public function delete(string $path): array
            {
                $this->calls[] = ['DELETE', $path];
                return ['data' => []];
            }
        };
        $property = new \ReflectionProperty(BaseCommand::class, 'client');
        foreach ($app->all() as $command) {
            if ($command instanceof BaseCommand) {
                $property->setValue($command, $client);
            }
        }

        $_SERVER['argv'] = ['p202', ...$argv, '--json'];
        $output = new BufferedOutput();
        $code = $app->run(null, $output);

        return [$code, $client->calls, $output->fetch()];
    }
}
