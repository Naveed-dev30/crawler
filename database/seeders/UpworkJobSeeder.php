<?php

namespace Database\Seeders;

use App\Models\UpworkJob;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class UpworkJobSeeder extends Seeder
{
    /**
     * Local dev data until the live Upwork API credentials are wired up.
     * Idempotent: keyed on job_id, safe to re-run.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $jobs = [
            [
                'job_id' => '~dummy0001', 'title' => 'Senior Laravel Developer for SaaS platform',
                'job_type' => 'hourly', 'hourly_min' => 35, 'hourly_max' => 60, 'currency' => 'USD',
                'skills' => ['Laravel', 'PHP', 'Vue.js', 'MySQL'],
                'client_country' => 'United States', 'client_total_spent' => 48200, 'client_payment_verified' => true,
                'posted_at' => $now->copy()->subHours(2),
                'description' => "We're scaling our B2B SaaS and need a senior Laravel engineer. Long-term, 30+ hrs/week. Experience with queues, multi-tenancy, and API design required.",
            ],
            [
                'job_id' => '~dummy0002', 'title' => 'React Native app bug fixing & release',
                'job_type' => 'fixed', 'budget_amount' => 800, 'currency' => 'USD',
                'skills' => ['React Native', 'JavaScript', 'iOS', 'Android'],
                'client_country' => 'United Kingdom', 'client_total_spent' => 12100, 'client_payment_verified' => true,
                'posted_at' => $now->copy()->subHours(5),
                'description' => "Existing RN app has a few crash bugs on Android 14 and needs a fresh App Store + Play Store release. Codebase is clean, well documented.",
            ],
            [
                'job_id' => '~dummy0003', 'title' => 'WordPress to headless migration',
                'job_type' => 'hourly', 'hourly_min' => 25, 'hourly_max' => 45, 'currency' => 'USD',
                'skills' => ['WordPress', 'Next.js', 'GraphQL', 'REST API'],
                'client_country' => 'Canada', 'client_total_spent' => 9800, 'client_payment_verified' => true,
                'posted_at' => $now->copy()->subHours(9),
                'description' => "Migrate a content-heavy WordPress site to a headless Next.js front end using WPGraphQL. SEO must be preserved.",
            ],
            [
                'job_id' => '~dummy0004', 'title' => 'Python data pipeline (ETL) engineer',
                'job_type' => 'hourly', 'hourly_min' => 40, 'hourly_max' => 70, 'currency' => 'USD',
                'skills' => ['Python', 'Airflow', 'PostgreSQL', 'AWS'],
                'client_country' => 'Germany', 'client_total_spent' => 76500, 'client_payment_verified' => true,
                'posted_at' => $now->copy()->subHours(14),
                'description' => "Build and maintain ETL pipelines pulling from several third-party APIs into a Postgres warehouse. Orchestrated with Airflow on AWS.",
            ],
            [
                'job_id' => '~dummy0005', 'title' => 'Shopify theme customization',
                'job_type' => 'fixed', 'budget_amount' => 450, 'currency' => 'USD',
                'skills' => ['Shopify', 'Liquid', 'CSS', 'JavaScript'],
                'client_country' => 'Australia', 'client_total_spent' => 3200, 'client_payment_verified' => false,
                'posted_at' => $now->copy()->subHours(20),
                'description' => "Customize a purchased Shopify theme: adjust product page layout, add a size guide modal, and tweak the cart drawer.",
            ],
            [
                'job_id' => '~dummy0006', 'title' => 'DevOps: Kubernetes + CI/CD setup',
                'job_type' => 'hourly', 'hourly_min' => 50, 'hourly_max' => 90, 'currency' => 'USD',
                'skills' => ['Kubernetes', 'Docker', 'GitHub Actions', 'Terraform'],
                'client_country' => 'Netherlands', 'client_total_spent' => 128000, 'client_payment_verified' => true,
                'posted_at' => $now->copy()->subDay(),
                'description' => "Set up a production-grade EKS cluster with GitOps deployments, autoscaling, and a full CI/CD pipeline. Infrastructure as code with Terraform.",
            ],
            [
                'job_id' => '~dummy0007', 'title' => 'Landing page design + build',
                'job_type' => 'fixed', 'budget_amount' => 600, 'currency' => 'USD',
                'skills' => ['HTML', 'Tailwind CSS', 'Figma', 'JavaScript'],
                'client_country' => 'United States', 'client_total_spent' => 5400, 'client_payment_verified' => true,
                'posted_at' => $now->copy()->subDays(2),
                'description' => "Design and build a high-converting SaaS landing page from a rough wireframe. Responsive, fast, and pixel-perfect.",
            ],
            [
                'job_id' => '~dummy0008', 'title' => 'AI chatbot integration (OpenAI)',
                'job_type' => 'hourly', 'hourly_min' => 45, 'hourly_max' => 80, 'currency' => 'USD',
                'skills' => ['OpenAI', 'Node.js', 'LangChain', 'RAG'],
                'client_country' => 'Singapore', 'client_total_spent' => 21700, 'client_payment_verified' => true,
                'posted_at' => $now->copy()->subDays(3),
                'description' => "Integrate a retrieval-augmented chatbot into our support portal. Vector DB already chosen (Pinecone). Node backend.",
            ],
        ];

        foreach ($jobs as $data) {
            $data['url'] = 'https://www.upwork.com/jobs/'.$data['job_id'];
            UpworkJob::updateOrCreate(['job_id' => $data['job_id']], $data);
        }
    }
}
